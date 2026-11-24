<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Forms\Api\Value;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\FeeComponentFailed;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Pricing\Api\ProvidesBase;
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
     * @return array<string, array<string, Money>> line source key → payer → amount owed: the component's ID, or
     *     `<ID>:<item>` for each item of a component that owes per item
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
        // or the fixed price without a ladder"): by structure, not by what a tier owes. It is charged only
        // when no component provides the event's base. [UNAPPROVED, #200 N7]
        $baseProvided = false;
        foreach ($components as $component) {
            if ($component instanceof ProvidesBase && $this->providesBase($component, $facts->registration->eventId)) {
                $baseProvided = true;
            }
        }

        $before = new StageTotals($currency);
        foreach (FeeStage::cases() as $stage) {
            $stageOwed = [];
            // A stage's computed values reach the later stages only, like its totals. [UNAPPROVED, #200 N12]
            $stageComputed = [];
            foreach ($components as $component) {
                if ($component->stage() !== $stage) {
                    continue;
                }
                if ($component instanceof FixedPrice && $baseProvided) {
                    continue;
                }
                $owed = $this->owedBy(
                    $component,
                    new FeeComponentInput($facts->registration, $facts->status, $facts, $before, $currency, $now),
                    $currency
                );
                if ($owed['items'] === null) {
                    $composed[$component->id()] = $owed['owed'];
                } else {
                    unset($composed[$component->id()]);
                    foreach ($owed['items'] as $item => $payers) {
                        $composed[$component->id() . ':' . $item] = $payers;
                    }
                }
                foreach ($owed['owed'] as $payer => $amount) {
                    $stageOwed[$payer] = isset($stageOwed[$payer]) ? $stageOwed[$payer]->plus($amount) : $amount;
                }
                $stageComputed = $this->withComputed($component, $stageComputed, $owed['computed'], $facts);
            }
            $before = $before->with($stage, $stageOwed);
            if ($stageComputed !== []) {
                $facts = $facts->withComputed($stageComputed);
            }
        }

        return $composed;
    }

    /**
     * The stage's computed values with a component's added: each a dotted key no other value of the facts, nor
     * of the stage, has yet.
     *
     * @param array<string, Value> $stage
     * @param array<array-key, mixed> $computed
     * @return array<string, Value>
     * @throws FeeComponentFailed on a malformed value or a key that is taken
     */
    private function withComputed(
        FeeComponent $component,
        array $stage,
        array $computed,
        RegistrationFacts $facts,
    ): array {
        foreach ($computed as $key => $value) {
            $key = (string) $key;
            if (!$value instanceof Value || !str_contains($key, '.') || $facts->has($key) || isset($stage[$key])) {
                $error = new FeeComponentFailed($component->id());
                throw $error;
            }
            $stage[$key] = $value;
        }

        return $stage;
    }

    /**
     * Whether the component provides the event's base; outside code, so a throw is the component's failure.
     *
     * @throws FeeComponentFailed when the component throws
     */
    private function providesBase(FeeComponent&ProvidesBase $component, int $eventId): bool
    {
        try {
            return $component->providesBase($eventId);
        } catch (\Throwable $thrown) {
            $error = new FeeComponentFailed($component->id(), $thrown);
            throw $error;
        }
    }

    /**
     * @return array{owed: array<string, Money>, items: ?array<string, array<string, Money>>,
     *     computed: array<array-key, mixed>} what each payer owes for the component in all, and per item when
     *     the component owes per item, and the computed values it returned
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
        if ($targets->items === []) {
            return [
                'owed' => $this->checked($component, $targets->owed, $currency),
                'items' => null,
                'computed' => $targets->computed,
            ];
        }
        $items = [];
        $owed = [];
        foreach ($targets->items as $item => $payers) {
            $item = (string) $item;
            // The line's source key (`<component>:<item>`) is stored in 64 characters.
            if (
                preg_match('/\A[a-z0-9_]{1,40}\z/', $item) !== 1
                || strlen($component->id()) + 1 + strlen($item) > 64
            ) {
                $error = new FeeComponentFailed($component->id());
                throw $error;
            }
            $items[$item] = $this->checked($component, $payers, $currency);
            foreach ($items[$item] as $payer => $amount) {
                $owed[$payer] = isset($owed[$payer]) ? $owed[$payer]->plus($amount) : $amount;
            }
        }

        return ['owed' => $owed, 'items' => $items, 'computed' => $targets->computed];
    }

    /**
     * @param array<string, Money> $payers
     * @return array<string, Money>
     */
    private function checked(FeeComponent $component, array $payers, Currency $currency): array
    {
        $owed = [];
        foreach ($payers as $payer => $amount) {
            $payer = (string) $payer;
            // Currency has one case today, so the last check cannot fail yet; it guards a second currency.
            // @phpstan-ignore instanceof.alwaysTrue, notIdentical.alwaysFalse
            if (!Payer::isValid($payer) || !$amount instanceof Money || $amount->currency() !== $currency) {
                $error = new FeeComponentFailed($component->id());
                throw $error;
            }
            $owed[$payer] = $amount;
        }

        return $owed;
    }
}
