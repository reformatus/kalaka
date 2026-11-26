<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\WooCommerce\Billing;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\ParticipantFields\Api\FieldKeyHeld;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\Tests\Integration\WooCommerce\Card\WooTestCase;
use Kalaka\WooCommerce\BillingField;
use Kalaka\WooCommerce\DeclareBillingFields;

/**
 * `kalaka/declare-billing-fields`: the five billing participant fields the module declares per event, as declarer
 * `woocommerce`, source `extension` and form-writable, with `billing_country` a choice of WooCommerce's countries.
 */
final class DeclareBillingFieldsTest extends WooTestCase
{
    protected function usesHpos(): bool
    {
        return true;
    }

    private function declare(): void
    {
        $this->runner->run(new DeclareBillingFields(), ['event_id' => $this->event], new CliActor(null));
    }

    /** @return array<string, ParticipantField> */
    private function fields(): array
    {
        $fields = [];
        foreach ((new ParticipantFields())->of($this->event, true) as $field) {
            $fields[$field->key] = $field;
        }

        return $fields;
    }

    private function seedFieldRow(string $key, string $source): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_participant_fields', [
            'event_id' => $this->event,
            'field_key' => $key,
            'version' => 1,
            'type' => BillingField::TEXT,
            'title' => $key,
            'source' => $source,
            'created_at' => '2026-11-13 08:00:00.000000',
            'updated_at' => '2026-11-13 08:00:00.000000',
            'position' => 1,
            'form_writable' => 1,
        ]);
    }

    /** @return list<array{value: string, label: string, retired: bool}> */
    private function countryOptions(): array
    {
        $options = [];
        foreach (WC()->countries->get_countries() as $code => $label) {
            $options[] = ['value' => strtolower((string) $code), 'label' => (string) $label, 'retired' => false];
        }

        return $options;
    }

    public function testItDeclaresTheFiveFieldsWithTheirTextsAndTheCountries(): void
    {
        $this->declare();

        $fields = $this->fields();
        self::assertSame(
            [
                BillingField::NAME,
                BillingField::COUNTRY,
                BillingField::POSTCODE,
                BillingField::CITY,
                BillingField::ADDRESS,
            ],
            array_keys($fields)
        );
        $expected = [
            BillingField::NAME => [BillingField::TEXT, BillingField::NAME_TITLE],
            BillingField::COUNTRY => ['kalaka/choice-field', BillingField::COUNTRY_TITLE],
            BillingField::POSTCODE => [BillingField::TEXT, BillingField::POSTCODE_TITLE],
            BillingField::CITY => [BillingField::TEXT, BillingField::CITY_TITLE],
            BillingField::ADDRESS => [BillingField::TEXT, BillingField::ADDRESS_TITLE],
        ];
        foreach ($expected as $key => [$type, $title]) {
            $field = $fields[$key];
            self::assertSame(ParticipantField::SOURCE_EXTENSION, $field->source, $key);
            self::assertTrue($field->formWritable, $key);
            self::assertSame('woocommerce', $field->declaredBy, $key);
            self::assertSame($type, $field->type, $key);
            self::assertSame($title, $field->title, $key);
        }
        self::assertSame([], $fields[BillingField::NAME]->options);
        self::assertSame($this->countryOptions(), $fields[BillingField::COUNTRY]->options);
    }

    public function testADeclarationIsIdempotent(): void
    {
        $this->declare();
        $this->declare();

        self::assertCount(5, $this->fields());
    }

    public function testItAdoptsASameTypeFormField(): void
    {
        $this->seedFieldRow(BillingField::NAME, ParticipantField::SOURCE_FORM);

        $this->declare();

        $field = $this->fields()[BillingField::NAME];
        self::assertSame(ParticipantField::SOURCE_EXTENSION, $field->source);
        self::assertSame(BillingField::NAME_TITLE, $field->title);
    }

    public function testAStaffFieldRefusesTheDeclaration(): void
    {
        $this->seedFieldRow(BillingField::NAME, ParticipantField::SOURCE_STAFF);

        try {
            $this->declare();
            self::fail('The declaration accepted a staff field.');
        } catch (FieldKeyHeld $held) {
            self::assertSame('kalaka_field_key_held', $held->errorCode());
        }
    }
}
