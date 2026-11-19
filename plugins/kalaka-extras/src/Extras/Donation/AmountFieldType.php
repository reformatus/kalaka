<?php

declare(strict_types=1);

namespace Kalaka\Extras\Donation;

use Kalaka\Forms\Api\AbstractFieldType;
use Kalaka\Forms\Api\FieldAnswer;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\MoneyRelevantFieldType;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\Money\MoneyText;

/**
 * An amount in whole forints (`kalaka/amount-field`), e.g. a donation: a whole number of at least 0, at most
 * `DIGITS` digits, spaces between the digits allowed. The block's optional `min`, `step` and `max` limit an
 * amount actually given: 0 (no amount) is always fine, a positive one must be at least `min`, a multiple of
 * `step` and at most `max`. Money-relevant (events-and-forms.md, "Fields"), so its value is locked after the
 * first write unless an organizer unlocks the field; core enforces the lock with additional forms (S60).
 * Prefill from a link is the inherited stored string; presets are not built yet. [UNAPPROVED N5]
 * The refusal messages [UNAPPROVED T4] and the value's text [UNAPPROVED T5] are proposed text.
 */
final class AmountFieldType extends AbstractFieldType implements MoneyRelevantFieldType
{
    public const BLOCK = 'kalaka/amount-field';

    /** At most this many digits: far below what `DECIMAL(26,8)` holds. */
    public const DIGITS = 12;

    public function blockName(): string
    {
        return self::BLOCK;
    }

    public function answer(FieldTypeInput $input): FieldAnswer
    {
        if (!is_string($input->value)) {
            throw new InvalidInput();
        }
        $digits = preg_replace('/[\s\p{Z}]+/u', '', self::trimmed($input->value)) ?? '';
        if (preg_match('/\A[0-9]{1,' . self::DIGITS . '}\z/', $digits) !== 1) {
            return FieldAnswer::error('Csak egész forintösszeget írhatsz ide.');
        }
        $amount = (int) $digits;
        if ($amount > 0) {
            $min = self::limit($input->attributes, 'min');
            if ($min !== null && $amount < $min) {
                return FieldAnswer::error(sprintf('Ha megadsz összeget, legalább %s legyen.', self::forints($min)));
            }
            $step = self::limit($input->attributes, 'step');
            if ($step !== null && $step > 0 && $amount % $step !== 0) {
                return FieldAnswer::error(sprintf('Az összeg %s többszöröse legyen.', self::forints($step)));
            }
            $max = self::limit($input->attributes, 'max');
            if ($max !== null && $amount > $max) {
                return FieldAnswer::error(sprintf('Legfeljebb %s-ot adhatsz meg.', self::forints($max)));
            }
        }

        return FieldAnswer::stored((string) $amount);
    }

    public function valueType(): ValueType
    {
        return ValueType::Number;
    }

    /** The stored whole number, e.g. `Value::number('5000', '5 000 Ft')`; anything else reads as none. */
    public function value(FieldTypeInput $input): Value
    {
        $stored = is_string($input->value) ? $input->value : '';
        if (preg_match('/\A[0-9]{1,' . self::DIGITS . '}\z/', $stored) !== 1) {
            return Value::none(ValueType::Number);
        }
        $amount = (int) $stored;

        return Value::number((string) $amount, self::forints($amount));
    }

    /** An amount's text (Hungarian typography; T5): `5 000 Ft`. */
    private static function forints(int $amount): string
    {
        return MoneyText::of(Money::of($amount, Currency::HUF));
    }

    /**
     * A limit the block sets: a whole number of at least 0, or null when it sets none.
     *
     * @param array<string, mixed> $attributes
     */
    private static function limit(array $attributes, string $name): ?int
    {
        $limit = $attributes[$name] ?? null;

        return is_int($limit) && $limit >= 0 ? $limit : null;
    }
}
