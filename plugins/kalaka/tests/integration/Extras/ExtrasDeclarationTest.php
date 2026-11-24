<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Extras;

use Kalaka\Extras\Api\ProposedExtras;
use Kalaka\Extras\Donation\DonationComponent;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Entry\CommandFailure;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\ParticipantFields\DeclareParticipantFields;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\Pricing\Api\PlainAmount;
use Kalaka\Pricing\Api\ProposedMoneySettings;
use Kalaka\Pricing\Cli\SettingsDocument;

/**
 * The extras define their participant fields (events-and-forms.md, "Fields"): a charge declares its field with its
 * key and type when it is saved, the donation's number field is declared by every save, and a form field joins a
 * declared field by its key and never creates it. [UNAPPROVED N1] [UNAPPROVED N2] [UNAPPROVED T1]
 */
final class ExtrasDeclarationTest extends ExtrasTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook
    public function set_up(): void
    {
        parent::set_up();
        // The base declares the fields as a first apply would; these tests start from an event with none.
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'kalaka_participant_fields', ['event_id' => $this->event]);
    }

    private function field(string $key): ?ParticipantField
    {
        foreach ((new ParticipantFields())->of($this->event, true) as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    /** @return list<string> the keys of the event's participant fields */
    private function keys(): array
    {
        return array_map(
            static fn (ParticipantField $field): string => $field->key,
            (new ParticipantFields())->of($this->event, true)
        );
    }

    /**
     * A form's save, through the sync's use case.
     *
     * @param array<string, mixed>|null $settings
     * @return list<string> the keys it skipped
     */
    private function formDeclares(string $key, string $type, ?array $settings = null): array
    {
        $skipped = $this->runner->run(
            new DeclareParticipantFields(),
            [
                'event_id' => $this->event,
                'fields' => [
                    [
                        'key' => $key,
                        'type' => $type,
                        'title' => 'Űrlap',
                        'options' => null,
                        'latest' => true,
                        'settings' => $settings,
                    ],
                ],
            ],
            new CliActor(null)
        );

        return array_values(array_filter(is_array($skipped) ? $skipped : [], 'is_string'));
    }

    public function testAChargeSaveDeclaresItsFieldWithItsTypeTitleAndFormWritable(): void
    {
        $this->applyCharges([
            self::item('ebed', 'Ebéd', '3500'),
            self::item('ejjel', 'Éjszaka', '1000', 'number'),
        ]);

        $ebed = $this->field('ebed');
        self::assertNotNull($ebed);
        self::assertSame('kalaka/consent-field', $ebed->type);
        self::assertSame(ParticipantField::SOURCE_EXTENSION, $ebed->source);
        self::assertSame('Ebéd', $ebed->title);
        self::assertTrue($ebed->formWritable);

        $ejjel = $this->field('ejjel');
        self::assertNotNull($ejjel);
        self::assertSame('kalaka/number-field', $ejjel->type);
        self::assertSame(ParticipantField::SOURCE_EXTENSION, $ejjel->source);
        self::assertSame(0, $ejjel->settings['decimals'], 'A multiplier counts whole units: the declarer fixes it.');

        $this->applyCharges([self::item('ebed', 'Déli ebéd', '3500')]);
        self::assertSame('Déli ebéd', $this->field('ebed')?->title, 'The charge\'s label is the field\'s title.');
    }

    public function testAChargesTypeReadsBackFromItsDeclaredField(): void
    {
        $this->applyCharges([self::item('ebed', 'Ebéd', '3500'), self::item('ejjel', 'Éjszaka', '1000', 'number')]);

        $types = [];
        foreach ($this->extras->charges($this->event) as $charge) {
            $types[$charge->key] = $charge->type;
        }
        self::assertSame(['ebed' => 'consent', 'ejjel' => 'number'], $types);
    }

    public function testTheDonationFieldIsDeclaredOnEveryApplyEvenWithNoCharges(): void
    {
        $this->applyCharges([]);

        $donation = $this->field(DonationComponent::KEY);
        self::assertNotNull($donation);
        self::assertSame('kalaka/number-field', $donation->type);
        self::assertSame(ParticipantField::SOURCE_EXTENSION, $donation->source);
        self::assertTrue($donation->formWritable);
        self::assertSame('Adomány', $donation->title);
        self::assertSame([DonationComponent::KEY], $this->keys());
    }

    public function testAFormFieldWithTheKeyJoinsTheDeclaredFieldAndCreatesNone(): void
    {
        $this->applyCharges([self::item('ebed', 'Ebéd', '3500')]);
        $before = $this->keys();

        self::assertSame([], $this->formDeclares('ebed', 'kalaka/consent-field'));
        self::assertSame([], $this->formDeclares(DonationComponent::KEY, 'kalaka/number-field', ['decimals' => 2]));

        self::assertSame($before, $this->keys(), 'A joining form field creates no new participant field.');
        self::assertSame(ParticipantField::SOURCE_EXTENSION, $this->field('ebed')?->source);
        self::assertSame('Ebéd', $this->field('ebed')?->title, 'The title stays the declarer\'s.');
        self::assertSame(['decimals' => 2], $this->field(DonationComponent::KEY)?->settings);

        $anna = $this->registerWith('00A0', ['ebed' => '1', 'adomany' => '1500.5']);
        self::assertSame(
            [
                'extras:ebed participant 3500 charge:extras:ebed:participant:1',
                'donation participant 1500.5 charge:donation:participant:1',
            ],
            $this->charges($anna)
        );
    }

    public function testAFormCreatedFieldOfTheSameTypeIsAdoptedByTheChargeSave(): void
    {
        self::assertSame([], $this->formDeclares('ebed', 'kalaka/consent-field'));
        $anna = $this->registerWith('00A0', ['ebed' => '1']);
        self::assertSame(ParticipantField::SOURCE_FORM, $this->field('ebed')?->source);
        $before = $this->keys();

        $this->applyCharges([self::item('ebed', 'Déli ebéd', '3500')]);

        $adopted = $this->field('ebed');
        self::assertSame(ParticipantField::SOURCE_EXTENSION, $adopted?->source);
        self::assertSame('Déli ebéd', $adopted->title);
        self::assertTrue($adopted->formWritable);
        self::assertSame(array_merge($before, [DonationComponent::KEY]), $this->keys());

        $this->evaluate($anna);
        self::assertSame(
            ['extras:ebed participant 3500 charge:extras:ebed:participant:1'],
            $this->charges($anna),
            'The value the form field stored is untouched and the charge calls it.'
        );
    }

    public function testARetiredFormFieldOfTheSameTypeIsAdoptedAndActiveAgain(): void
    {
        self::assertSame([], $this->formDeclares('ebed', 'kalaka/consent-field'));
        // A form that no longer holds it retires it.
        self::assertSame([], $this->formDeclares('masik', 'kalaka/consent-field'));
        self::assertTrue($this->field('ebed')?->retired);

        $this->applyCharges([self::item('ebed', 'Ebéd', '3500')]);

        self::assertFalse($this->field('ebed')?->retired);
        self::assertSame(ParticipantField::SOURCE_EXTENSION, $this->field('ebed')?->source);
    }

    public function testAFormCreatedDonationFieldIsAdoptedByTheApply(): void
    {
        self::assertSame([], $this->formDeclares(DonationComponent::KEY, 'kalaka/number-field', ['decimals' => 2]));

        $this->applyCharges([]);

        self::assertSame(ParticipantField::SOURCE_EXTENSION, $this->field(DonationComponent::KEY)?->source);
        self::assertSame('Adomány', $this->field(DonationComponent::KEY)?->title);
        self::assertSame(2, $this->field(DonationComponent::KEY)?->settings['decimals'] ?? null);
    }

    public function testAFieldOfAnotherTypeOrASourceThatIsNotAFormRefusesTheSaveWholeAndTheCliSaysSo(): void
    {
        $this->applyCharges([self::item('ebed', 'Ebéd', '3500')]);
        $this->declareField('kiegeszito', 'kalaka/text-field', null, 5, 'staff');
        $this->declareField('price_tier', 'kalaka/choice-field', null, 6);
        $this->declareField('szoveg', 'kalaka/text-field', null, 7, 'form');

        foreach (['kiegeszito', 'price_tier', 'szoveg'] as $key) {
            $proposed = [self::item('ebed', 'Ebéd', '4000'), self::item($key, 'Más', '1000')];
            try {
                $this->extras->propose($this->event, $proposed);
                self::fail('Refused: ' . $key);
            } catch (RuleViolation) {
                self::assertSame(['3500'], array_map(
                    static fn ($charge): string => PlainAmount::of($charge->amount),
                    $this->extras->charges($this->event)
                ));
            }
        }

        // Written around the early check, the apply refuses too, at the declaration, and nothing of it stays.
        $input = [
            'event_id' => $this->event,
            'currency' => 'HUF',
            'charges' => [self::item('ebed', 'Ebéd', '4000'), self::item('kiegeszito', 'Más', '1000')],
        ];
        try {
            $this->settings->apply(
                $this->event,
                new ProposedMoneySettings(components: [new ProposedExtras([], $input)]),
                new CliActor(null)
            );
            self::fail('Refused.');
        } catch (RuleViolation) {
            self::assertSame(['3500'], array_map(
                static fn ($charge): string => PlainAmount::of($charge->amount),
                $this->extras->charges($this->event)
            ));
        }

        $document = new SettingsDocument($this->settings);
        try {
            $document->proposed($this->event, ['components' => ['extras' => ['charges' => [
                self::item('kiegeszito', 'Kiegészítő', '1000'),
            ]]]]);
            self::fail('Refused.');
        } catch (CommandFailure $failure) {
            self::assertStringStartsWith('The settings of "extras" are refused: ', $failure->getMessage());
        }
    }

    public function testADeclaredFieldKeepsItsTypeSoAChargeCannotChangeIt(): void
    {
        $this->applyCharges([self::item('ejjel', 'Éjszaka', '1000', 'number')]);

        $this->expectException(RuleViolation::class);
        $this->extras->propose($this->event, [self::item('ejjel', 'Éjszaka', '1000')]);
    }

    public function testANumberChargeStillRefusesAFieldThatAllowsDecimals(): void
    {
        $this->applyCharges([self::item('ejjel', 'Éjszaka', '1000', 'number')]);
        // A form field joined it with decimals (the sync keeps the form field's settings).
        self::assertSame([], $this->formDeclares('ejjel', 'kalaka/number-field', ['decimals' => 2]));

        $this->expectException(InvalidInput::class);
        $this->extras->propose($this->event, [self::item('ejjel', 'Éjszaka', '1000', 'number')]);
    }

    public function testATakenOutChargesFieldIsRetiredAndDeclaringItAgainMakesItActive(): void
    {
        $this->applyCharges([self::item('ebed', 'Ebéd', '3500'), self::item('ejjel', 'Éj', '1000', 'number')]);
        $this->applyCharges([self::item('ejjel', 'Éj', '1000', 'number')]);

        self::assertSame(ParticipantField::SOURCE_EXTENSION, $this->field('ebed')?->source);
        self::assertTrue($this->field('ebed')?->retired);
        self::assertFalse($this->field('ejjel')?->retired);
        self::assertFalse($this->field(DonationComponent::KEY)?->retired);

        $this->applyCharges([]);
        self::assertTrue($this->field('ejjel')?->retired);
        self::assertFalse($this->field(DonationComponent::KEY)?->retired, 'The donation is never retired.');

        $this->applyCharges([self::item('ebed', 'Ebéd', '3500')]);
        self::assertFalse($this->field('ebed')?->retired);
        self::assertTrue($this->field('ejjel')?->retired);
    }

    public function testEveryChargeFieldAndTheDonationStoreTheExtrasAsDeclarerAndAreMoneyRelevant(): void
    {
        $this->applyCharges([self::item('ebed', 'Ebéd', '3500'), self::item('ejjel', 'Éj', '1000', 'number')]);

        $fields = new ParticipantFields();
        foreach (['ebed', 'ejjel', DonationComponent::KEY] as $key) {
            $field = $this->field($key);
            self::assertNotNull($field);
            self::assertTrue($field->moneyRelevant, $key);
            self::assertTrue($fields->lockedAfterFirstWrite($field), $key);
            self::assertSame('kalaka-extras', $this->declaredBy($key), $key);
        }
    }

    public function testAKeyAnotherDeclarerHoldsRefusesTheChargeSave(): void
    {
        $this->declareField('ebed', 'kalaka/consent-field', null, 0, 'extension', 'kalaka-aid');

        $this->expectException(RuleViolation::class);
        $this->extras->propose($this->event, [self::item('ebed', 'Ebéd', '3500')]);
    }

    private function declaredBy(string $key): ?string
    {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare(
            'SELECT declared_by FROM ' . $wpdb->prefix . 'kalaka_participant_fields'
            . ' WHERE event_id = %d AND field_key = %s',
            $this->event,
            $key
        ));

        return is_string($value) ? $value : null;
    }

    public function testAPreviewWritesNoParticipantField(): void
    {
        $this->extras->propose(
            $this->event,
            [self::item('ebed', 'Ebéd', '3500'), self::item('ejjel', 'Éj', '1', 'number')]
        );
        $this->extras->propose($this->event, []);

        self::assertSame([], $this->keys());
    }

    public function testApplyingTheSameDocumentAgainChangesNoParticipantField(): void
    {
        $charges = [self::item('ebed', 'Ebéd', '3500'), self::item('ejjel', 'Éjszaka', '1000', 'number')];
        $this->applyCharges($charges);
        $rows = $this->fieldRows();

        $this->applyCharges($charges);

        self::assertSame($rows, $this->fieldRows(), 'No row, version or update time changes.');
    }

    /** @return list<array<string, mixed>> */
    private function fieldRows(): array
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . $wpdb->prefix . 'kalaka_participant_fields WHERE event_id = %d ORDER BY field_key',
                $this->event
            ),
            ARRAY_A
        );
    }

    public function testAChargeKeyedLikeThePriceTierIsRefused(): void
    {
        $this->declareField('price_tier', 'kalaka/choice-field', null, 3);

        $this->expectException(RuleViolation::class);
        $this->extras->propose($this->event, [self::item('price_tier', 'Díj', '1000')]);
    }

    public function testANumberChargeFixesItsFieldsDecimalsAtZero(): void
    {
        $this->applyCharges([self::item('ejjel', 'Éjszaka', '1000', 'number')]);

        self::assertSame(['decimals' => 0], $this->field('ejjel')?->settings['declared'] ?? null);
        self::assertArrayNotHasKey('declared', $this->field(DonationComponent::KEY)?->settings ?? []);
    }
}
