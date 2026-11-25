<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Registrations\Api\RegistrationFacts;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Pricing\FeeComposer;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;

/**
 * The one fee composition (money.md, "Materializer"), read-only: the event's current settings, the
 * registration's facts and the registered fee components, composed stage by stage. The materializer records
 * its difference; confirmation (later the late fee) runs the same composition
 * inside the decision and writes nothing, so the target never comes from charge lines. [UNAPPROVED N1]
 */
final class FeeComposition
{
    public function __construct(
        private readonly CoreFeeComponents $components = new CoreFeeComponents(),
        private readonly FeeComposer $composer = new FeeComposer(),
        private readonly Events $events = new Events(),
        private readonly Registrations $registrations = new Registrations(),
        private readonly Ledger $ledger = new Ledger(),
        private readonly EventRetention $retention = new EventRetention(),
    ) {
    }

    /**
     * The IDs of the components registered now, and the retention line's: the materializer reverses a recorded
     * line of one of them that the
     * composition no longer names.
     *
     * @return list<string>
     */
    public function componentIds(): array
    {
        return [
            ...array_map(static fn (FeeComponent $component): string => $component->id(), $this->components->list()),
            Retention::LINE,
        ];
    }

    /**
     * What the registration owes now, or null when it or its event does not exist. Reads no ledger line. With
     * `$becoming`, what it would owe once it has that status: the staff dialogs' preview, the same composition.
     *
     * @throws FeeComponentFailed when a component throws or returns something invalid
     */
    public function of(
        RegistrationKey $key,
        \DateTimeImmutable $now,
        ?RegistrationStatus $becoming = null,
    ): ?ComposedFee {
        $event = $this->events->find($key->eventId);
        if ($event === null) {
            return null;
        }
        $facts = $this->registrations->factsOf($key, $event, $now);
        if ($facts === null) {
            return null;
        }
        if ($becoming !== null) {
            $facts = $facts->becoming($becoming, $now);
        }

        return new ComposedFee(
            $facts,
            $event->currency,
            $this->withRetention(
                $facts,
                $event->currency,
                $this->composer->compose($facts, $event->currency, $now, $this->components->list()),
                $this->components->list(),
                $now
            )
        );
    }

    /**
     * Retention (money.md, "Withdrawal, removal and substitution"): a registration that withdrew or was removed owes,
     * per payer, what the event's rule keeps, asked once for every payer together (`RetentionRule::kept()`, with the
     * payers' net payments read from payment facts only and the fee the registration would owe if it were still
     * `registered`, composed from `$components`; a component that cannot say leaves the rule an empty composition,
     * so leaving never fails on it) and held to that payer's own payments,
     * so nobody owes more than they paid and nothing is refunded. A registration that is `registered` or `waitlisted`
     * has none; its line, if one was recorded, reads zero and the materializer reverses it.
     *
     * The one place retention is applied: the materializer's composition and the money settings preview both come
     * here. `$rule` is the rule to apply (a proposed one in the preview); null reads the event's saved rule.
     *
     * @param array<string, array<string, Money>> $composed
     * @param list<FeeComponent> $components the components the fee is composed from for the rule's input
     * @return array<string, array<string, Money>>
     * @throws FeeComponentFailed named `retention` when the event's stored rule is not registered, or the rule throws
     */
    public function withRetention(
        RegistrationFacts $facts,
        Currency $currency,
        array $composed,
        array $components,
        \DateTimeImmutable $now,
        ?RetentionRule $rule = null,
    ): array {
        if ($facts->status !== RegistrationStatus::Withdrawn && $facts->status !== RegistrationStatus::Removed) {
            return $composed;
        }
        $rule ??= $this->retention->ruleOf($facts->registration->eventId);
        $paid = [];
        foreach ($this->ledger->paymentFactsOf($facts->registration) as $fact) {
            $paid[$fact->payer] = ($paid[$fact->payer] ?? Money::zero($currency))->minus($fact->amount);
        }
        ksort($paid, SORT_STRING);
        try {
            $asRegistered = $facts->becoming(RegistrationStatus::Registered, $now);
            $fee = $this->composer->compose($asRegistered, $currency, $now, $components);
        } catch (FeeComponentFailed) {
            $fee = [];
        }
        try {
            $asked = $rule->kept(new RetentionInput($facts, $currency, $paid, $fee));
        } catch (\Throwable $failure) {
            $error = new FeeComponentFailed(Retention::LINE, $failure);
            throw $error;
        }
        $kept = [];
        foreach ($paid as $payer => $amount) {
            $keep = $asked[$payer] ?? null;
            if (!$keep instanceof Money) {
                continue;
            }
            // Never more than the payer's own payments.
            $keep = $keep->isGreaterThan($amount) ? $amount : $keep;
            if ($keep->isPositive()) {
                $kept[(string) $payer] = $keep;
            }
        }
        if ($kept !== []) {
            $composed[Retention::LINE] = $kept;
        }

        return $composed;
    }
}
