<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * One registration in a money settings preview: what each payer owes now and what it would owe under the
 * proposed settings, summed over the components. `problem` is set when it cannot be composed (it would fail
 * in the apply too); then both sides are empty.
 */
final readonly class PreviewRow
{
    /**
     * @param array<string, Money> $before payer → owed under the current settings
     * @param array<string, Money> $after payer → owed under the proposed settings
     */
    public function __construct(
        public RegistrationKey $registration,
        public RegistrationStatus $status,
        public array $before,
        public array $after,
        public ?\Throwable $problem = null,
    ) {
    }

    /** Whether any payer's amount changes. */
    public function changes(): bool
    {
        foreach (array_unique([...array_keys($this->before), ...array_keys($this->after)]) as $payer) {
            $before = $this->before[$payer] ?? null;
            $after = $this->after[$payer] ?? null;
            if ($before === null || $after === null) {
                $nonZero = $before ?? $after;
                if ($nonZero !== null && ($nonZero->isPositive() || $nonZero->isNegative())) {
                    return true;
                }
                continue;
            }
            if (!$before->isEqualTo($after)) {
                return true;
            }
        }

        return false;
    }
}
