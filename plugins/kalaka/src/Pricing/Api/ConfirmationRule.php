<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Decision\DecisionDefault;
use Kalaka\Foundation\Api\Decision\DecisionInput;
use Kalaka\Foundation\Api\Decision\DecisionRule;
use Kalaka\Foundation\Api\Decision\Transition;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\PaymentFact;
use Kalaka\Pricing\FullAmountThreshold;
use Kalaka\Pricing\ManualConfirmationRecord;
use Kalaka\Pricing\ManualConfirmationRepository;
use Kalaka\Pricing\UnconfirmationRepository;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * Confirmation, a core decision (money.md, "Confirmation"), defined only for `registered` registrations.
 *
 * Each payer with something owed must reach its own threshold: core's is the payer's full target, and a
 * `ConfirmationThreshold` may lower it. The target comes from the one fee composition, run read-only inside the
 * decision (never from charge lines); the payments come from the Ledger's payment facts. The composition
 * includes the manual charges and discounts (the `manual` fee component), so a payer's target is what it owes
 * now, a discount lowering it. Every payer counts,
 * the congregation too (the owner's ruling 2026-11-10). [UNAPPROVED N1]
 *
 * - → `confirmed` when the payments reach every threshold, the manual flag is set, or nothing is due. Its
 *   effective time is the value date (paid time online) of the payment that reached the last required
 *   threshold, the flag's set time, or the moment of the check when nothing is due; with both payments and
 *   the flag, the earlier. [UNAPPROVED N10]
 * - → `unconfirmed` from `confirmed` only by staff's explicit unconfirm (`kalaka/unconfirm`, newer than the
 *   current `confirmed` record), effective at its time: a refund, a later charge or a higher threshold never
 *   unconfirms (the owner's ruling 2026-11-19); a lower threshold can confirm at the next evaluation; a later
 *   payment or correction that reaches the threshold confirms again. [UNAPPROVED N9, N12]
 * - No record is the base state: a `registered` registration with none reads `unconfirmed`, derived, never
 *   written (`defaultState()`, the owner's ruling 2026-11-19); other statuses read no state. [UNAPPROVED N7]
 * - Withdrawal or removal closes the sequence: a `confirmed` registration that is no longer `registered` gets
 *   `unconfirmed`, so a restore or admission writes a new `confirmed` record if it qualifies and the old one is
 *   never revived. [UNAPPROVED N8]
 */
final class ConfirmationRule implements DecisionRule
{
    public const KIND = 'confirmation';

    public const CONFIRMED = 'confirmed';

    public const UNCONFIRMED = 'unconfirmed';

    public function __construct(
        private readonly FeeComposition $composition = new FeeComposition(),
        private readonly ?ConfirmationThreshold $threshold = null,
        private readonly Ledger $ledger = new Ledger(),
        private readonly ManualConfirmationRepository $flags = new ManualConfirmationRepository(),
        private readonly UnconfirmationRepository $unconfirmations = new UnconfirmationRepository(),
    ) {
    }

    public function kind(): string
    {
        return self::KIND;
    }

    /** Approved by the owner 2026-11-18. */
    public function label(): string
    {
        return 'Visszaigazolás';
    }

    /** Approved by the owner 2026-11-18. */
    public function states(): array
    {
        return [self::CONFIRMED => 'Visszaigazolva', self::UNCONFIRMED => 'Nincs visszaigazolva'];
    }

    /** No record reads `unconfirmed`, and only a `registered` registration has a confirmation state. */
    public function defaultState(): DecisionDefault
    {
        return new DecisionDefault(self::UNCONFIRMED, [RegistrationStatus::Registered]);
    }

    /**
     * Whether the registration's payments reach every required threshold now, or nothing is due: what staff's
     * unconfirm must not contradict (`ManualConfirmations::canUnconfirm()`). False when it does not exist.
     *
     * @throws FeeComponentFailed when a component throws or returns something invalid
     */
    public function paymentsQualify(RegistrationKey $key, \DateTimeImmutable $now): bool
    {
        $composed = $this->composition->of($key, $now);
        if ($composed === null) {
            return false;
        }
        $below = [];

        return $this->reached($composed, $this->ledger->paymentFactsOf($key), $now, $below) !== false;
    }

    public function decide(DecisionInput $input): ?Transition
    {
        $key = $input->registration;
        $composed = $this->composition->of($key, $input->now);
        if ($composed === null) {
            return null;
        }
        $current = $input->decisions[self::KIND] ?? null;
        $confirmed = $current !== null && $current->state === self::CONFIRMED;

        if ($composed->facts->status !== RegistrationStatus::Registered) {
            return $confirmed ? new Transition(self::UNCONFIRMED, $input->now, Subject::registration($key)) : null;
        }

        if (!$confirmed) {
            $flag = $this->flags->find($key);
            $below = [];
            $reached = $this->reached($composed, $this->ledger->paymentFactsOf($key), $input->now, $below);

            return $this->confirmation($key, $reached, $flag !== null && $flag->isSet() ? $flag : null, $input);
        }

        $unconfirmed = $this->unconfirmations->find($key);
        if ($unconfirmed === null || $unconfirmed->unconfirmedAt <= $current->recordedAt) {
            return null;
        }

        return new Transition(
            self::UNCONFIRMED,
            $unconfirmed->unconfirmedAt,
            new Subject('unconfirmation', $key->eventId, $key->code)
        );
    }

    /**
     * Whether the payments reach every required threshold: the payment fact that reached the last one, true
     * when nothing is due, false when a payer is below its threshold.
     *
     * @param list<PaymentFact> $facts
     * @param array<string, true> $below gets the payers below their threshold
     */
    private function reached(
        ComposedFee $composed,
        array $facts,
        \DateTimeImmutable $now,
        array &$below,
    ): PaymentFact|bool {
        $last = true;
        foreach ($composed->targets() as $payer => $target) {
            $threshold = $this->thresholdOf($composed, $payer, $target, $now);
            if ($threshold->countsAsZero() || $threshold->isNegative()) {
                continue;
            }
            $reachedBy = self::reachedBy($facts, $payer, $threshold);
            if ($reachedBy === null) {
                $below[$payer] = true;
            } elseif ($last === true || $reachedBy->valueAt() > $last->valueAt()) {
                $last = $reachedBy;
            }
        }

        return $below === [] ? $last : false;
    }

    /** The payer's threshold, held between zero and its target. */
    private function thresholdOf(ComposedFee $composed, string $payer, Money $target, \DateTimeImmutable $now): Money
    {
        $threshold = ($this->threshold ?? new FullAmountThreshold())
            ->threshold(new ConfirmationThresholdInput($composed->facts, $payer, $target, $now));
        if ($threshold->isGreaterThan($target)) {
            return $target;
        }

        return $threshold->isNegative() ? Money::zero($target->currency()) : $threshold;
    }

    /**
     * The payment fact after which the payer's payments, in value order, reach the threshold and stay there;
     * null when they do not reach it now.
     *
     * @param list<PaymentFact> $facts ordered by `valueAt()`, then ID
     */
    private static function reachedBy(array $facts, string $payer, Money $threshold): ?PaymentFact
    {
        $paid = Money::zero($threshold->currency());
        $reachedBy = null;
        foreach ($facts as $fact) {
            if ($fact->payer !== $payer) {
                continue;
            }
            $paid = $paid->minus($fact->amount);
            $short = $threshold->minus($paid);
            $reaches = $short->countsAsZero() || $short->isNegative();
            if (!$reaches) {
                $reachedBy = null;
            } elseif ($reachedBy === null) {
                $reachedBy = $fact;
            }
        }

        return $reachedBy;
    }

    /** The `confirmed` transition when the payments or the flag qualify, else null. */
    private function confirmation(
        RegistrationKey $key,
        PaymentFact|bool $reached,
        ?ManualConfirmationRecord $flag,
        DecisionInput $input,
    ): ?Transition {
        $candidates = [];
        if ($reached instanceof PaymentFact) {
            $candidates[] = [$reached->valueAt(), new Subject('ledger_line', $key->eventId, (string) $reached->id)];
        } elseif ($reached === true) {
            $candidates[] = [$input->now, Subject::registration($key)];
        }
        if ($flag !== null) {
            $candidates[] = [$flag->setAt, new Subject('manual_confirmation', $key->eventId, $key->code)];
        }
        if ($candidates === []) {
            return null;
        }
        usort($candidates, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return new Transition(self::CONFIRMED, $candidates[0][0], $candidates[0][1]);
    }
}
