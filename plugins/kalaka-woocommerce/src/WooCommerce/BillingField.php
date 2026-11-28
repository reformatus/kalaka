<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

use Kalaka\Forms\Api\FieldKind;
use Kalaka\ParticipantFields\Api\ParticipantFields;

/**
 * The five participant fields a card payment's billing details come from, and the check of the field each
 * key already has: the code defines the fields (source `extension`, form-writable) and adopts a `form`
 * field of the same type, so a key `ParticipantFields::checkDeclarable()` refuses (a `staff` or `system`
 * field, a field of another type, another declarer's field) is refused here as the declaration would
 * refuse it. `billing_country` is a choice whose options are ISO 3166-1 alpha-2: the option value is the
 * lower-case code, the label WooCommerce's own country name.
 */
final class BillingField
{
    /** The declarer the billing fields store (`declared_by`): the module's ID. */
    public const DECLARER = 'woocommerce';

    /** Core's text field type: Forms' Api has no constant for it. */
    public const TEXT = 'kalaka/text-field';

    public const NAME = 'billing_name';
    public const COUNTRY = 'billing_country';
    public const POSTCODE = 'billing_postcode';
    public const CITY = 'billing_city';
    public const ADDRESS = 'billing_address';

    /** The title of `billing_name`, the declarer's text. [UNAPPROVED T94] */
    public const NAME_TITLE = 'Számlázási név';

    /** The title of `billing_country`, the declarer's text. [UNAPPROVED T95] */
    public const COUNTRY_TITLE = 'Számlázási ország';

    /** The title of `billing_postcode`, the declarer's text. [UNAPPROVED T96] */
    public const POSTCODE_TITLE = 'Számlázási irányítószám';

    /** The title of `billing_city`, the declarer's text. [UNAPPROVED T97] */
    public const CITY_TITLE = 'Számlázási település';

    /** The title of `billing_address`, the declarer's text. [UNAPPROVED T98] */
    public const ADDRESS_TITLE = 'Számlázási utca, házszám';

    public function __construct(
        private readonly ParticipantFields $fields = new ParticipantFields(),
    ) {
    }

    /**
     * The five fields as the code declares them, in order: key, type, title and, for the country, its options.
     *
     * @return list<array{
     *     key: string,
     *     type: string,
     *     title: string,
     *     options: ?list<array{value: string, label: string}>,
     * }>
     */
    public function definitions(): array
    {
        return [
            ['key' => self::NAME, 'type' => self::TEXT, 'title' => self::NAME_TITLE, 'options' => null],
            [
                'key' => self::COUNTRY,
                'type' => FieldKind::CHOICE,
                'title' => self::COUNTRY_TITLE,
                'options' => self::countryOptions(),
            ],
            ['key' => self::POSTCODE, 'type' => self::TEXT, 'title' => self::POSTCODE_TITLE, 'options' => null],
            ['key' => self::CITY, 'type' => self::TEXT, 'title' => self::CITY_TITLE, 'options' => null],
            ['key' => self::ADDRESS, 'type' => self::TEXT, 'title' => self::ADDRESS_TITLE, 'options' => null],
        ];
    }

    /**
     * Whether every billing key is free for this declarer, as the declaration would judge it.
     *
     * @throws \Kalaka\ParticipantFields\Api\FieldKeyHeld when a key is not free
     */
    public function check(int $eventId): void
    {
        foreach ($this->definitions() as $field) {
            $this->fields->checkDeclarable($eventId, $field['key'], $field['type'], self::DECLARER);
        }
    }

    /**
     * The options of `billing_country`: ISO 3166-1 alpha-2, the value the lower-case code and the label
     * WooCommerce's own country name. [UNAPPROVED T99]
     *
     * @return list<array{value: string, label: string}>
     */
    private static function countryOptions(): array
    {
        $options = [];
        foreach (WC()->countries->get_countries() as $code => $label) {
            $options[] = ['value' => strtolower((string) $code), 'label' => (string) $label];
        }

        return $options;
    }
}
