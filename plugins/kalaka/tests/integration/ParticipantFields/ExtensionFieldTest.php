<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\ParticipantFields;

use Kalaka\Forms\DefinitionCheck;
use Kalaka\Forms\Module;
use Kalaka\Forms\ParticipantField\SharedKeyCheck;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\Tests\Integration\Forms\FormsTestCase;
use Kalaka\Tests\Integration\Foundation\AdminProbe;

/**
 * An extension declares a participant field (`kalaka/declare-extension-field`), and a form that joins an
 * extension's choice field offers only the options the extension declared, any subset of them
 * (`extension_option`), with option conditions as on any choice.
 */
final class ExtensionFieldTest extends FormsTestCase
{
    private const EVENT = 7;

    private const TIERS = [
        ['value' => 'korai', 'label' => 'Korai ár'],
        ['value' => 'alap', 'label' => 'Alap ár'],
        ['value' => 'gy', 'label' => 'Gyülekezeti ár'],
    ];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names it
    public function set_up(): void
    {
        parent::set_up();

        (new Module())->registerMeta();
        wp_set_current_user($this->fullAccessUser());
    }

    /** @param ?list<array{value: string, label: string}> $options */
    private function declare(
        string $key,
        ?array $options,
        string $title = 'Díjkategória',
        string $type = 'kalaka/choice-field',
    ): void {
        $this->runner->run(
            new AdminProbe(static function (array $input, Context $context) use ($key, $options, $title, $type): void {
                (new ParticipantFields())
                    ->declareExtensionField($context, self::EVENT, $key, $type, $title, $options, true, 'test_module');
            }),
            [],
            new CliActor(null)
        );
    }

    private function declared(string $key): ParticipantField
    {
        foreach ((new ParticipantFields())->of(self::EVENT, true) as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }
        self::fail('No field ' . $key);
    }

    /** @param list<array<string, mixed>> $options */
    private function choiceOn(string $key, array $options): string
    {
        return $this->block('kalaka/choice-field', ['key' => $key, 'label' => 'Melyik ár?', 'options' => $options]);
    }

    /** @param list<array<string, mixed>> $options */
    private function save(array $options): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', '/wp/v2/kalaka_form');
        $request->set_body_params([
            'title' => 'Pótlap',
            'status' => 'draft',
            'content' => $this->choiceOn('price_tier', $options),
            'meta' => ['kalaka_event_id' => self::EVENT, 'kalaka_placement' => 'additional'],
        ]);
        $response = rest_do_request($request);
        self::assertInstanceOf(\WP_REST_Response::class, $response);

        return $response;
    }

    public function testAnExtensionDeclaresItsFieldWithItsTextsAndRedeclaresItsOptions(): void
    {
        $this->declare('price_tier', self::TIERS);

        $field = $this->declared('price_tier');
        self::assertSame(ParticipantField::SOURCE_EXTENSION, $field->source);
        self::assertTrue($field->formWritable);
        self::assertSame('Díjkategória', $field->title);
        self::assertSame('kalaka/choice-field', $field->type);

        // A new tier adds an option, a removed one is retired with its label, a renamed one is renamed.
        $this->declare('price_tier', [
            ['value' => 'alap', 'label' => 'Alap'],
            ['value' => 'gy', 'label' => 'Gyülekezeti ár'],
            ['value' => 'teljes', 'label' => 'Teljes ár'],
        ]);

        self::assertSame(
            [
                ['value' => 'korai', 'label' => 'Korai ár', 'retired' => true],
                ['value' => 'alap', 'label' => 'Alap', 'retired' => false],
                ['value' => 'gy', 'label' => 'Gyülekezeti ár', 'retired' => false],
                ['value' => 'teljes', 'label' => 'Teljes ár', 'retired' => false],
            ],
            $this->declared('price_tier')->options
        );

        // Declared again, a retired option is active again.
        $this->declare('price_tier', self::TIERS);
        self::assertFalse($this->declared('price_tier')->options[0]['retired']);
    }

    public function testADeclarationAdoptsAFormFieldOfTheSameTypeAndRefusesAnotherType(): void
    {
        $this->insertChecked(
            $this->choiceOn('price_tier', [['value' => 'a', 'label' => 'A']]),
            ['kalaka_event_id' => self::EVENT, 'kalaka_placement' => 'additional']
        );
        self::assertSame(ParticipantField::SOURCE_FORM, $this->declared('price_tier')->source);

        $this->declare('price_tier', self::TIERS);
        $adopted = $this->declared('price_tier');
        self::assertSame(ParticipantField::SOURCE_EXTENSION, $adopted->source);
        self::assertSame('Díjkategória', $adopted->title);

        $this->declare('aid', null, 'Támogatás', 'kalaka/text-field');
        $this->expectException(RuleViolation::class);
        $this->declare('aid', self::TIERS);
    }

    public function testAFormMayNotGiveANumberFieldTheDeclarerFixedAtWholeNumbersDecimals(): void
    {
        $this->runner->run(
            new AdminProbe(static function (array $input, Context $context): void {
                (new ParticipantFields())->declareExtensionField(
                    $context,
                    self::EVENT,
                    'ejjel',
                    'kalaka/number-field',
                    'Éjszaka',
                    null,
                    true,
                    'test_module',
                    0
                );
            }),
            [],
            new CliActor(null)
        );
        $request = static function (int $decimals): \WP_REST_Response {
            $request = new \WP_REST_Request('POST', '/wp/v2/kalaka_form');
            $request->set_body_params([
                'title' => 'Pótlap',
                'status' => 'draft',
                'content' => '<!-- wp:kalaka/number-field ' . wp_json_encode(
                    ['key' => 'ejjel', 'label' => 'Éjszaka', 'decimals' => $decimals]
                ) . ' /-->',
                'meta' => ['kalaka_event_id' => self::EVENT, 'kalaka_placement' => 'additional'],
            ]);
            $response = rest_do_request($request);
            self::assertInstanceOf(\WP_REST_Response::class, $response);

            return $response;
        };

        $refused = $request(2);
        self::assertSame(400, $refused->get_status());
        $data = $refused->get_data();
        self::assertIsArray($data);
        self::assertSame(DefinitionCheck::message(SharedKeyCheck::EXTENSION_DECIMALS, 'ejjel'), $data['message']);
        self::assertSame(201, $request(0)->get_status());
        self::assertSame(
            ['decimals' => 0],
            $this->declared('ejjel')->settings['declared'] ?? null,
            'The sync keeps what the declarer fixed.'
        );
    }

    public function testAFormJoiningAnExtensionChoiceMayNotAddAnOption(): void
    {
        $this->declare('price_tier', self::TIERS);

        $response = $this->save([...self::TIERS, ['value' => 'ingyenes', 'label' => 'Ingyenes']]);

        self::assertSame(400, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        // The generic save message, with the code: no new text.
        self::assertSame(DefinitionCheck::message(SharedKeyCheck::EXTENSION_OPTION, 'price_tier'), $data['message']);
        self::assertStringContainsString('extension_option', (string) $data['message']);
        self::assertCount(3, $this->declared('price_tier')->options);
    }

    public function testAFormMayOfferASubsetWithItsOwnLabelsAndTheUnionLeavesTheDeclarersOptionsAlone(): void
    {
        $this->declare('price_tier', self::TIERS);

        $response = $this->save([['value' => 'gy', 'label' => 'Gyülekezeti']]);

        self::assertSame(201, $response->get_status());
        self::assertSame(
            [
                ['value' => 'korai', 'label' => 'Korai ár', 'retired' => false],
                ['value' => 'alap', 'label' => 'Alap ár', 'retired' => false],
                ['value' => 'gy', 'label' => 'Gyülekezeti ár', 'retired' => false],
            ],
            $this->declared('price_tier')->options
        );
    }

    public function testARetiredOptionIsStillTheDeclarersSoAFormHoldingItStillSaves(): void
    {
        $this->declare('price_tier', self::TIERS);
        $this->declare('price_tier', [self::TIERS[1]]);

        self::assertSame(201, $this->save([self::TIERS[0], self::TIERS[1]])->get_status());
    }

    public function testOptionConditionsOnAnExtensionChoiceWork(): void
    {
        $this->declare('price_tier', self::TIERS);
        $level = [['value' => 'nincs', 'label' => 'Nincs'], ['value' => 'teljes', 'label' => 'Teljes']];
        $onlyFull = [
            'match' => 'all',
            'items' => [['key' => 'tamogatas', 'operator' => 'equals', 'value' => 'teljes']],
        ];
        $content = $this->slotFields()
            . $this->block('kalaka/choice-field', ['key' => 'tamogatas', 'label' => 'Támogatás', 'options' => $level])
            . $this->choiceOn('price_tier', [
                self::TIERS[1],
                self::TIERS[2] + ['condition' => $onlyFull],
            ]);
        $form = $this->readForm(
            $this->insertChecked($content, ['kalaka_event_id' => self::EVENT, 'kalaka_placement' => 'registration'])
        );
        $definition = $this->forms->definition($form);
        $slot = ['name' => 'Teszt Elek', 'email' => 'teszt@example.org'];

        $none = $this->forms->availability($definition, $slot + ['tamogatas' => 'nincs'], $this->eventFacts());
        $full = $this->forms->availability($definition, $slot + ['tamogatas' => 'teljes'], $this->eventFacts());

        self::assertSame(['alap'], $none->offeredOptions('price_tier'));
        self::assertSame(['alap', 'gy'], $full->offeredOptions('price_tier'));
    }

    private function eventFacts(): ValueContext
    {
        return new ValueContext([
            EventValues::NAME => Value::text('Teszt tábor', 'Teszt tábor'),
            EventValues::REGISTRATION_OPENS => Value::none(ValueType::Date),
            EventValues::REGISTRATION_CLOSES => Value::none(ValueType::Date),
            EventValues::START => Value::date('2027-07-10', '2027-07-10'),
            EventValues::END => Value::none(ValueType::Date),
        ]);
    }
}
