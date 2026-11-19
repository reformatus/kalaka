<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Pricing\Api\ManualConfirmations;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Staff's explicit unconfirm (the owner's ruling 2026-11-19), the only way a `confirmed` registration becomes
 * `unconfirmed` while it stays `registered`. It records the unconfirm with its reason, who and when, and
 * revokes the manual flag if it is set; the evaluation then unconfirms the registration in the same run.
 *
 * - Allowed only while `ManualConfirmations::canUnconfirm()` holds: confirmed by payments or the flag, and the
 *   payments below a threshold now. Otherwise a `RuleViolation` (422): payments that reach the threshold, or
 *   a confirmation because nothing was due.
 * - A registration that is not `confirmed` stays as it is: `{changed: false}`, nothing written.
 * - Afterwards a payment or correction that reaches the threshold confirms it again, as always.
 *
 * [UNAPPROVED N12]
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
        if (!$this->confirmations->isConfirmed($key)) {
            return false;
        }
        if (!$this->confirmations->canUnconfirm($key, $context->now())) {
            throw new RuleViolation();
        }
        if ($flag !== null && $flag->isSet()) {
            $flag->revokedAt = $context->now();
            $this->flags->upsert($flag, $context);
        }

        $record = $this->unconfirmations->findForUpdate($key, $context);
        $by = self::userOf($context->actor());
        if ($record === null) {
            $record = new UnconfirmationRecord($key, $context->now(), $by, $reason);
        } else {
            $record->unconfirmedAt = $context->now();
            $record->unconfirmedBy = $by;
            $record->reason = $reason;
        }
        $this->unconfirmations->upsert($record, $context);

        return true;
    }
}
