<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Revokes the manual confirmation flag, at the run's moment; the reason goes to the audit entry. The
 * evaluation then unconfirms the registration in the same run if its payments are below the threshold. A flag
 * that is not set stays as it is. [UNAPPROVED N6]
 */
final class RevokeManualConfirmation extends ManualConfirmationChange
{
    public static function name(): string
    {
        return 'kalaka/revoke-manual-confirmation';
    }

    protected function change(
        RegistrationKey $key,
        ?ManualConfirmationRecord $flag,
        string $reason,
        Context $context,
    ): bool {
        unset($key, $reason);
        if ($flag === null || !$flag->isSet()) {
            return false;
        }
        $flag->revokedAt = $context->now();
        $this->flags->upsert($flag, $context);

        return true;
    }
}
