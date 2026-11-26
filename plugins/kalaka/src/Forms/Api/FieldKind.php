<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * What kind of field a participant field is, for a rule that must tell a consent from a quantity: its block
 * type and, for a number field, the decimals its stored settings hold (0 when it has none). Money reads this
 * from the registration's facts, never the field's block name from a table.
 */
final readonly class FieldKind
{
    private const CONSENT = 'kalaka/consent-field';
    private const NUMBER = 'kalaka/number-field';

    public function __construct(public string $block, public int $decimals = 0)
    {
    }

    public function isConsent(): bool
    {
        return $this->block === self::CONSENT;
    }

    public function isNumber(): bool
    {
        return $this->block === self::NUMBER;
    }
}
