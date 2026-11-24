<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Status;

use Kalaka\Registrations\Api\StatusAct;

/** Record a withdrawal (visszavont): the registration leaves and frees its seats. [UNAPPROVED N510] */
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
