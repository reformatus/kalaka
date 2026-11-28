<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Status;

use Kalaka\Registrations\Api\StatusAct;

/** Admit a waitlisted registration: it becomes `registered` and takes its seats again. */
final class AdmitRegistration extends StatusChange
{
    public static function name(): string
    {
        return StatusAct::Admit->useCase();
    }

    protected function act(): StatusAct
    {
        return StatusAct::Admit;
    }
}
