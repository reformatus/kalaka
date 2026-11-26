<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Status;

use Kalaka\Registrations\Api\StatusAct;

/**
 * Restore a withdrawn or removed registration, by full access only: it returns to the status it left from, `registered`
 * (taking its seats again) or `waitlisted` (when it was never registered). [UNAPPROVED N510, N519]
 */
final class RestoreRegistration extends StatusChange
{
    public static function name(): string
    {
        return StatusAct::Restore->useCase();
    }

    protected function act(): StatusAct
    {
        return StatusAct::Restore;
    }
}
