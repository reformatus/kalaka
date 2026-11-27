<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Pricing\Api\ManualConfirmations;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Staff's explicit unconfirm, "Visszaigazolás visszavonása" (the owner's ruling 2026-11-21): the one way to take
 * a confirmation back. It records the unconfirm with its reason, who and when, and revokes the manual flag if it
 * is set; the evaluation then writes `unconfirmed` in the same run and decides confirmation again at once
 * (the rule's own second check), so payments that reach every threshold, or nothing due, confirm it again.
 *
 * - Allowed on any confirmed registration: no threshold condition and no refusal.
 * - A registration that is not `confirmed` stays as it is: `{changed: false}`, nothing written.
 * - Not idempotent on a confirmed registration: a repeat after an at-once re-confirm is a new act, with a
 *   second pair of records and a second audit entry.
 *
 */
final class Unconfirm extends ManualConfirmationChange
{
    public function __construct(
        ManualConfirmationRepository $flags = new ManualConfirmationRepository(),
        private readonly UnconfirmationRepository $unconfirmations = new UnconfirmationRepository(),
        private readonly ManualConfirmations $confirmations = new ManualConfirmations(),
    ) {
        parent::__construct($flags);
    }

    public static function name(): string
    {
        return 'kalaka/unconfirm';
    }

    protected function change(
        RegistrationKey $key,
        ?ManualConfirmationRecord $flag,
        string $reason,
        Context $context,
    ): bool {
        $confirmedAt = $this->confirmations->confirmedRecordedAt($key);
        if ($confirmedAt === null) {
            return false;
        }
        if ($flag !== null && $flag->isSet()) {
            $flag->revokedAt = $context->now();
            $this->flags->upsert($flag, $context);
        }

        // The attempt's start time was taken before the lock: another act may have recorded the `confirmed` record
        // after it. The unconfirm is later than the record that is current under the lock, one microsecond at
        // least, the order the evaluation records decisions in too.
        $at = max($context->now(), $confirmedAt->modify('+1 microsecond'));
        $record = $this->unconfirmations->findForUpdate($key, $context);
        $by = self::userOf($context->actor());
        if ($record === null) {
            $record = new UnconfirmationRecord($key, $at, $by, $reason);
        } else {
            $record->unconfirmedAt = $at;
            $record->unconfirmedBy = $by;
            $record->reason = $reason;
        }
        $this->unconfirmations->upsert($record, $context);

        return true;
    }
}
