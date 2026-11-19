<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Sets the manual confirmation flag ("may come without paying", money.md, "Confirmation") with its reason and
 * the run's moment as its set time; the evaluation then confirms a `registered` registration in the same run.
 * A set flag stays as it is: its set time and reason are kept. [UNAPPROVED N6]
 */
final class SetManualConfirmation extends ManualConfirmationChange
{
    public static function name(): string
    {
        return 'kalaka/set-manual-confirmation';
    }

    protected function change(
        RegistrationKey $key,
        ?ManualConfirmationRecord $flag,
        string $reason,
        Context $context,
    ): bool {
        if ($flag !== null && $flag->isSet()) {
            return false;
        }
        if ($flag === null) {
            $flag = new ManualConfirmationRecord($key, $context->now(), self::userOf($context->actor()), $reason);
        } else {
            $flag->setAt = $context->now();
            $flag->setBy = self::userOf($context->actor());
            $flag->reason = $reason;
            $flag->revokedAt = null;
        }
        $this->flags->upsert($flag, $context);

        return true;
    }
}
