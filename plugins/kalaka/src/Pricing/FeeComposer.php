<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\FeeComponentFailed;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Pricing\Api\StageTotals;
use Kalaka\Registrations\Api\RegistrationFacts;
use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * Composes the fee stage by stage (money.md, "Materializer"): tier → support → share → aid → extras. Each
 * component sees the totals of the earlier stages only. Pure: it writes nothing and reads only what the
 * components read. Shared by the materializer and the money settings preview.
 */
final class FeeComposer
{
    /**
     * What each component says each payer owes now, by component ID. Only a `registered` registration
     * composes; every other status owes zero.
     *
     * Retention (money.md, "Withdrawal, removal and substitution") is an empty step here: a withdrawn or
     * removed registration owes nothing. Default; re-asked in S23, which fills the step.
     *
     * @param list<FeeComponent> $components
     * @return array<string, array<string, Money>> component ID → payer → amount owed
     * @throws FeeComponentFailed when a component throws or returns a malformed payer or another currency
     */
    public function compose(
        RegistrationFacts $facts,
        Currency $currency,
        \DateTimeImmutable $now,
        array $components,
    ): array {
        $composed = [];
        foreach ($components as $component) {
            $composed[$component->id()] = [];
        }
        if ($facts->status !== RegistrationStatus::Registered) {
            return $composed;
        }

        // The fixed price is the base only without a ladder (money.md, "Congregation share": "the chosen tier,
        // or the fixed price without a ladder"): it composes last in the tier stage, and owes nothing once
        // another tier-stage component owes something. [UNAPPROVED N3]
        $last = static fn (FeeComponent $component): int => $component instanceof FixedPrice ? 1 : 0;
        usort($components, static fn (FeeComponent $a, FeeComponent $b): int => $last($a) <=> $last($b));

        $before = new StageTotals($currency);
        foreach (FeeStage::cases() as $stage) {
            $stageOwed = [];
            foreach ($components as $component) {
                if ($component->stage() !== $stage) {
                    continue;
                }
                if ($component instanceof FixedPrice && self::owesAny($stageOwed)) {
                    continue;
                }
                [$owed, $lines] = $this->owedBy(
                    $component,
                    new FeeComponentInput($facts->registration, $facts->status, $facts, $before, $currency, $now),
                    $currency
                );
                if ($lines === []) {
                    $composed[$component->id()] = $owed;
                }
                foreach ($lines as $key => $payers) {
                    $composed[$component->id() . '.' . $key] = $payers;
                }
                foreach ($owed as $payer => $amount) {
                    $stageOwed[$payer] = isset($stageOwed[$payer]) ? $stageOwed[$payer]->plus($amount) : $amount;
                }
            }
            $before = $before->with($stage, $stageOwed);
        }

        return $composed;
    }

    /** @param array<string, Money> $owed */
    private static function owesAny(array $owed): bool
    {
        foreach ($owed as $amount) {
            if ($amount->isPositive() || $amount->isNegative()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{array<string, Money>, array<string, array<string, Money>>} what each payer owes in all, and
     *     the lines kept apart, if the component returned any
     */
    private function owedBy(FeeComponent $component, FeeComponentInput $input, Currency $currency): array
    {
        try {
            $targets = $component->targets($input);
        } catch (\Throwable $thrown) {
            $error = new FeeComponentFailed($component->id(), $thrown);
            throw $error;
        }

        // The types say FeeTargets of Money; a component is outside code, so the values are checked anyway.
        // @phpstan-ignore instanceof.alwaysTrue
        if (!$targets instanceof FeeTargets) {
            $error = new FeeComponentFailed($component->id());
            throw $error;
        }
        $lines = [];
        foreach ($targets->lines as $key => $payers) {
            $key = (string) $key;
            // A line's source key is the component's ID, a dot and the line key, at most 64 characters.
            if (preg_match('/\A[a-z][a-z0-9_]*\z/', $key) !== 1 || strlen($component->id() . '.' . $key) > 64) {
                $error = new FeeComponentFailed($component->id());
                throw $error;
            }
            $lines[$key] = $this->checked($component, $payers, $currency);
        }

        if ($lines === []) {
            return [$this->checked($component, $targets->owed, $currency), []];
        }
        // Lines given: what is owed in all is their sum, whatever `owed` says (the constructor takes both).
        // [UNAPPROVED N18]
        $owed = [];
        foreach ($lines as $payers) {
            foreach ($payers as $payer => $amount) {
                $owed[$payer] = isset($owed[$payer]) ? $owed[$payer]->plus($amount) : $amount;
            }
        }

        return [$owed, $lines];
    }

    /**
     * @param array<array-key, mixed> $payers
     * @return array<string, Money>
     */
    private function checked(FeeComponent $component, array $payers, Currency $currency): array
    {
        $owed = [];
        foreach ($payers as $payer => $amount) {
            $payer = (string) $payer;
            // Currency has one case today, so the last check cannot fail yet; it guards a second currency.
            // @phpstan-ignore notIdentical.alwaysFalse
            if (!Payer::isValid($payer) || !$amount instanceof Money || $amount->currency() !== $currency) {
                $error = new FeeComponentFailed($component->id());
                throw $error;
            }
            $owed[$payer] = $amount;
        }

        return $owed;
    }
}
