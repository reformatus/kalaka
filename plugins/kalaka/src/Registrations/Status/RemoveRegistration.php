<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Status;

use Kalaka\Registrations\Api\StatusAct;

/** Remove a registration (törölt): it leaves and frees its seats; the reason is optional. */
final class RemoveRegistration extends StatusChange
{
    public static function name(): string
    {
        return StatusAct::Remove->useCase();
    }

    protected function act(): StatusAct
    {
        return StatusAct::Remove;
    }
}
