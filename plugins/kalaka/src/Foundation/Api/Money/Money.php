<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Money;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Vendor\Brick\Math\BigDecimal;
use Kalaka\Vendor\Brick\Math\RoundingMode;

/**
 * An exact amount of money in one currency, stored as DECIMAL(26,8) text.
 *
 * Only this class uses the bundled brick/math; its API exposes no type of it.
 */
final readonly class Money
{
    private const SCALE = 8;

    /** Largest absolute value that fits DECIMAL(26,8). */
    private const LIMIT = '999999999999999999.99999999';

    private function __construct(private BigDecimal $value, private Currency $currency)
    {
    }

    /**
     * @throws InvalidInput When the text is not a plain decimal inside the DECIMAL(26,8) range.
     */
    public static function of(int|string $amount, Currency $currency): self
    {
        $text = (string) $amount;
        if (preg_match('/\A-?[0-9]+(\.[0-9]{1,8})?\z/', $text) !== 1) {
            throw new InvalidInput();
        }

        $value = BigDecimal::of($text)->toScale(self::SCALE);
        if (!self::fits($value)) {
            throw new InvalidInput();
        }

        return new self($value, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(BigDecimal::zero()->toScale(self::SCALE), $currency);
    }

    /** The DECIMAL(26,8) text form, such as "48000.00000000" or "-0.50000000". */
    public function amount(): string
    {
        return $this->value->toString();
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    /** @throws SystemFailure On another currency or a result outside the DECIMAL(26,8) range. */
    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->result($this->value->plus($other->value));
    }

    /** @throws SystemFailure On another currency or a result outside the DECIMAL(26,8) range. */
    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->result($this->value->minus($other->value));
    }

    public function negated(): self
    {
        return $this->result($this->value->negated());
    }

    /**
     * @return int -1, 0 or 1
     * @throws SystemFailure On another currency.
     */
    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->value->compareTo($other->value) <=> 0;
    }

    /** @throws SystemFailure On another currency. */
    public function isEqualTo(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    /** @throws SystemFailure On another currency. */
    public function isLessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    /** @throws SystemFailure On another currency. */
    public function isGreaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isNegative(): bool
    {
        return $this->value->getSign() < 0;
    }

    public function isPositive(): bool
    {
        return $this->value->getSign() > 0;
    }

    /** True when the absolute amount is below one minor unit of the currency (HUF: below 0.01). */
    public function countsAsZero(): bool
    {
        $minorUnit = BigDecimal::one()->withPointMovedLeft($this->currency->minorUnitDecimals());

        return $this->value->abs()->compareTo($minorUnit) < 0;
    }

    /**
     * Drops the part below one minor unit toward zero, then rounds toward positive infinity
     * to the payable unit, so this agrees with countsAsZero().
     *
     * @throws SystemFailure When the result leaves the DECIMAL(26,8) range.
     */
    public function roundedUpToPayableUnit(): self
    {
        $value = $this->value
            ->toScale($this->currency->minorUnitDecimals(), RoundingMode::Down)
            ->toScale($this->currency->payableUnitDecimals(), RoundingMode::Ceiling);

        return $this->result($value);
    }

    private static function fits(BigDecimal $value): bool
    {
        return $value->abs()->compareTo(self::LIMIT) <= 0;
    }

    private function result(BigDecimal $value): self
    {
        $value = $value->toScale(self::SCALE);
        if (!self::fits($value)) {
            throw new SystemFailure();
        }

        return new self($value, $this->currency);
    }

    // With one currency PHPStan knows the check cannot fail. Once a second currency exists these ignores
    // are reported as unmatched, which is the reminder to remove them.
    // @phpstan-ignore void.pure
    private function assertSameCurrency(self $other): void
    {
        // @phpstan-ignore notIdentical.alwaysFalse
        if ($this->currency !== $other->currency) {
            throw new SystemFailure();
        }
    }
}
