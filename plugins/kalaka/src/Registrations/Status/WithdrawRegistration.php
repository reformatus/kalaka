<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Status;

use Kalaka\Registrations\Api\StatusAct;

/** Record a withdrawal (lemondott): the registration leaves and frees its seats. */
final class WithdrawRegistration extends StatusChange
{
    public static function name(): string
    {
        return StatusAct::Withdraw->useCase();
    }

    protected function act(): StatusAct
    {
        return StatusAct::Withdraw;
    }
}
