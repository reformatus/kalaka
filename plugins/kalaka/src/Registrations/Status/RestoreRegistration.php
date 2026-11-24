<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Status;

use Kalaka\Registrations\Api\StatusAct;

/** Restore a withdrawn or removed registration: it becomes `registered` and takes its seats again. [UNAPPROVED N510] */
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
