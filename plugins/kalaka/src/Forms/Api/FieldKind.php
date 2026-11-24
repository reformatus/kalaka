<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * What kind of field a participant field is, for a rule that must tell a consent from a quantity: its block
 * type and, for a number field, the decimals its stored settings hold (0 when it has none). Money reads this
 * from the registration's facts, never the field's block name from a table. [UNAPPROVED N19]
 */
final readonly class FieldKind
{
    /** The block type of a choice field: the one name a declarer gives (`declareExtensionField()`). */
    public const CHOICE = 'kalaka/choice-field';

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
