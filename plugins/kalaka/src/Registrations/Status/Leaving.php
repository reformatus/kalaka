<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Status;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Pricing\Api\FeeComponentFailed;
use Kalaka\Pricing\Api\FeeComposition;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\Seats;
use Kalaka\Registrations\Api\StatusAct;
use Kalaka\Registrations\Api\StatusActRefused;
use Kalaka\Registrations\Registration;
use Kalaka\Registrations\RegistrationRepository;

/**
 * The one copy of what a leaving act does and what it would do to money (registrations-and-identity.md, "Status,
 * capacity and waitlist"; money.md, "Withdrawal, removal and substitution"): take the registration's lock
 * (`StatusLock`), write the status and free every seat (`Seats::releaseAll()`). The staff acts
 * (`StatusChange`) and the participant's own withdrawal (`WithdrawOwnRegistration`) both call it, so the lock, seat
 * and money sequence is written once. It writes no reason and no mail; the caller's actor and the runner's audit
 * entry carry who and why. [UNAPPROVED N510, N660]
 *
 * @internal Registrations' status acts only
 */
final class Leaving
{
    public function __construct(
        private readonly RegistrationRepository $registrations = new RegistrationRepository(),
        private readonly Seats $seats = new Seats(),
        private readonly FeeComposition $composition = new FeeComposition(),
        private readonly Ledger $ledger = new Ledger(),
        private readonly Events $events = new Events(),
    ) {
    }

    /**
     * Leaves under the lock: the status and the version, every seat freed. False when the registration is already in
     * the act's target state (a repeat that lost the race): nothing is written.
     *
     * @throws NotFound
     * @throws StatusActRefused the status under the lock does not allow the act
     */
    public function leave(StatusAct $act, RegistrationKey $key, Context $context): bool
    {
        $registration = (new StatusLock($this->registrations))->locked($act, $key, $context);
        if ($registration === null) {
            return false;
        }
        $registration->status = $act->target();
        $this->registrations->upsert($registration, $context);
        $this->seats->releaseAll($key, $context);

        return true;
    }

    /**
     * What the act does to each payer's money: what was paid, the balance now and the balance once the act's own
     * composition (the materializer's, retention included) is recorded. Signed amounts as the money reads give them
     * (above zero owed, below zero overpaid). Null when a fee component cannot say what is owed. Plain reads:
     * nothing is written or refunded. The caller decides who may see which payer.
     *
     * @return list<array{payer: string, paid: string, balance_now: string, balance_after: string}>|null
     */
    public function money(RegistrationStatus $target, RegistrationKey $key, Context $context): ?array
    {
        try {
            $after = $this->composition->of($key, $context->now(), $target);
        } catch (FeeComponentFailed) {
            return null;
        }
        $event = $this->events->find($key->eventId);
        if ($after === null || $event === null) {
            return null;
        }
        $summary = $this->ledger->summaryOf($key);
        $targets = $after->targets();
        $payers = array_unique([...array_keys($summary->payers()), ...array_keys($targets)]);
        sort($payers, SORT_STRING);
        $rows = [];
        foreach ($payers as $payer) {
            $payer = (string) $payer;
            $now = $summary->of($payer, $event->currency);
            $target = $targets[$payer] ?? Money::zero($event->currency);
            $rows[] = [
                'payer' => $payer,
                'paid' => $now->paid->amount(),
                'balance_now' => $now->balance->amount(),
                'balance_after' => $target->minus($now->paid)->amount(),
            ];
        }

        return $rows;
    }
}
