<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Extras;

use Kalaka\Extras\Api\ProposedExtras;
use Kalaka\Forms\Module;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Entry\CommandFailure;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\ParticipantFields\Api\FieldDecimalsHeld;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\Pricing\Api\PlainAmount;
use Kalaka\Pricing\Api\ProposedMoneySettings;
use Kalaka\Pricing\Cli\SettingsDocument;

/**
 * A number charge fixes its field's decimals at 0, so adopting a key refuses the whole save when a form of the event
 * holds a number field under it with other decimals (it would close that form), and a charge's type reads back from
 * its field's type whatever the field's source, so a shown document applies again.
 */
final class ExtrasFormDecimalsTest extends ExtrasTestCase
{
    private Runner $production;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook
    public function set_up(): void
    {
        parent::set_up();
        // A form save declares the event's participant fields through the production runner: it joins the harness's.
        $this->production = Runner::current();
        Runner::publish($this->runner);
        (new Module())->registerMeta();
        wp_set_current_user($this->fullAccessUser());
        // The base declares the fields as a first apply would; these tests start from forms that hold them.
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'kalaka_participant_fields', ['event_id' => $this->event]);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook
    public function tear_down(): void
    {
        Runner::publish($this->production);
        parent::tear_down();
    }

    /** An additional form of the event with one number field. */
    private function numberForm(string $key, int $decimals, string $status = 'publish', string $title = 'Űrlap'): int
    {
        $id = wp_insert_post([
            'post_type' => 'kalaka_form',
            'post_status' => $status,
            'post_title' => $title,
            'post_content' => serialize_block([
                'blockName' => 'kalaka/number-field',
                'attrs' => ['key' => $key, 'label' => $key, 'decimals' => $decimals],
                'innerBlocks' => [],
                'innerHTML' => '',
                'innerContent' => [],
            ]),
            'meta_input' => ['kalaka_event_id' => $this->event, 'kalaka_placement' => 'additional'],
        ], true);
        self::assertIsInt($id);

        return $id;
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

    /** @return list<string> the stored charge amounts */
    private function amounts(): array
    {
        return array_map(
            static fn ($charge): string => PlainAmount::of($charge->amount),
            $this->extras->charges($this->event)
        );
    }

    public function testAFormCreatedNumberFieldIsAdoptedByANumberChargeAndTheShownDocumentAppliesAgain(): void
    {
        $this->numberForm('ejjel', 0);
        self::assertSame(ParticipantField::SOURCE_FORM, $this->field('ejjel')?->source);

        $this->applyCharges([self::item('ejjel', 'Éjszaka', '1000', 'number')]);

        self::assertSame(ParticipantField::SOURCE_EXTENSION, $this->field('ejjel')?->source);
        $document = new SettingsDocument($this->settings);
        $shown = $document->show($this->event);
        self::assertSame('number', $shown['components']['extras']['charges'][0]['type']);

        $rows = $this->fieldRows();
        $this->applySettings($document->proposed($this->event, $shown)->components[0]);
        self::assertSame($rows, $this->fieldRows(), 'The shown document changes nothing when it is applied.');
    }

    public function testAChargeWhoseKeyIsStillAFormNumberFieldShowsAsANumber(): void
    {
        $this->applyCharges([self::item('ejjel', 'Éjszaka', '1000', 'number')]);
        // The declared field is gone and a form field holds the key again (the state before the adoption).
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'kalaka_participant_fields', ['event_id' => $this->event]);
        $this->numberForm('ejjel', 0);
        self::assertSame(ParticipantField::SOURCE_FORM, $this->field('ejjel')?->source);

        $shown = (new SettingsDocument($this->settings))->show($this->event);

        self::assertSame('number', $shown['components']['extras']['charges'][0]['type']);
        $this->applySettings(
            (new SettingsDocument($this->settings))->proposed($this->event, $shown)->components[0]
        );
        self::assertSame(ParticipantField::SOURCE_EXTENSION, $this->field('ejjel')?->source);
    }

    /** @return array<string, array{string}> */
    public static function holdingStatuses(): array
    {
        return ['published' => ['publish'], 'draft' => ['draft'], 'scheduled' => ['future']];
    }

    /** @dataProvider holdingStatuses */
    public function testAFormHoldingTheKeyWithDecimalsRefusesTheWholeSaveAtThePreviewAndWritesNothing(
        string $status
    ): void {
        $this->applyCharges([self::item('ebed', 'Ebéd', '3500')]);
        $post = ['post_date' => '2099-01-01 08:00:00', 'post_date_gmt' => '2099-01-01 08:00:00'];
        $form = $this->numberForm('ejjel', 2, 'publish', 'Éjszakák');
        if ($status !== 'publish') {
            wp_update_post(['ID' => $form, 'post_status' => $status] + ($status === 'future' ? $post : []));
        }
        $fields = $this->fieldRows();

        try {
            $this->extras->propose(
                $this->event,
                [self::item('ebed', 'Ebéd', '4000'), self::item('ejjel', 'Éjszaka', '1000', 'number')]
            );
            self::fail('Refused.');
        } catch (FieldDecimalsHeld $held) {
            self::assertSame('ejjel', $held->key);
            self::assertSame([['form_id' => $form, 'title' => 'Éjszakák']], $held->forms);
            self::assertSame('kalaka_field_decimals_held', $held->errorCode());
        }

        self::assertSame(['3500'], $this->amounts());
        self::assertSame($fields, $this->fieldRows());
        self::assertSame(get_post_status($form), $status);
    }

    public function testTheApplyRefusesWhenTheFormAppearedAfterThePreviewAndTheWholeApplyWritesNothing(): void
    {
        $this->applyCharges([self::item('ebed', 'Ebéd', '3500')]);
        $proposed = $this->extras->propose(
            $this->event,
            [self::item('ebed', 'Ebéd', '4000'), self::item('ejjel', 'Éjszaka', '1000', 'number')]
        );
        $form = $this->numberForm('ejjel', 2);
        $fields = $this->fieldRows();

        try {
            $this->settings->apply(
                $this->event,
                new ProposedMoneySettings(fixedPrice: $this->huf('9000'), components: [$proposed]),
                new CliActor(null)
            );
            self::fail('Refused.');
        } catch (FieldDecimalsHeld $held) {
            self::assertSame([['form_id' => $form, 'title' => 'Űrlap']], $held->forms);
        }

        self::assertSame(['3500'], $this->amounts());
        self::assertNull($this->settings->fixedPrice($this->event), 'The same apply writes no fixed price.');
        self::assertSame($fields, $this->fieldRows());
        self::assertNotNull($this->forms()->form($form), 'The form still opens.');
    }

    public function testTheCliNamesTheKeyAndTheFormsInOneLine(): void
    {
        $first = $this->numberForm('ejjel', 2, 'publish', 'Első');
        $second = $this->numberForm('ejjel', 1, 'draft', 'Második');

        try {
            (new SettingsDocument($this->settings))->proposed($this->event, ['components' => ['extras' => [
                'charges' => [self::item('ejjel', 'Éjszaka', '1000', 'number')],
            ]]]);
            self::fail('Refused.');
        } catch (CommandFailure $failure) {
            self::assertSame(
                'The settings of "extras" are refused: the key "ejjel" is a number charge, which fixes its decimals '
                . 'at 0, and these forms of the event hold a number field with that key and other decimals: #'
                . $first . ' "Első", #' . $second . ' "Második". Change the fields to 0 decimals or choose '
                . 'another key.',
                $failure->getMessage()
            );
            self::assertInstanceOf(FieldDecimalsHeld::class, $failure->getPrevious());
        }
    }

    public function testFormsWithNoDecimalsAreAdoptedAndANumberFieldOfAnotherKeyOrAConsentChargeIsNotAffected(): void
    {
        $this->numberForm('ejjel', 0);
        $this->numberForm('masik', 2);

        $this->applyCharges([self::item('ejjel', 'Éjszaka', '1000', 'number'), self::item('ebed', 'Ebéd', '3500')]);

        self::assertSame(ParticipantField::SOURCE_EXTENSION, $this->field('ejjel')?->source);
        self::assertSame(['1000', '3500'], $this->amounts());
    }

    public function testTheDonationKeepsItsDecimalsFromAFormThatHoldsIt(): void
    {
        $this->numberForm('adomany', 2);

        $this->applyCharges([]);

        self::assertSame(ParticipantField::SOURCE_EXTENSION, $this->field('adomany')?->source);
        self::assertSame(2, $this->field('adomany')?->settings['decimals'] ?? null);
    }

    private function forms(): \Kalaka\Forms\Api\Forms
    {
        return new \Kalaka\Forms\Api\Forms();
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
}
