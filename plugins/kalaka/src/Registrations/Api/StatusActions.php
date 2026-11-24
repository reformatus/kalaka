<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\Status\AdmitRegistration;
use Kalaka\Registrations\Status\RemoveRegistration;
use Kalaka\Registrations\Status\RestoreRegistration;
use Kalaka\Registrations\Status\StatusChange;
use Kalaka\Registrations\Status\WithdrawRegistration;

/**
 * Runs a status act (`StatusAct`) for a caller outside Registrations (the command line): the use case through the
 * runner, as the actor, so the grant, the audit and the evaluation are the use case's. It holds no rule.
 * [UNAPPROVED N510]
 */
final class StatusActions
{
    public function __construct(private readonly ?Runner $runner = null)
    {
    }

    /**
     * @param bool $preview true: what the act would do, nothing written
     * @return array{changed: bool, status: string, over_capacity: bool, seats: list<array<string, mixed>>}
     * @throws \Kalaka\Foundation\Api\Error\KalakaException as the use case throws it
     */
    public function run(
        StatusAct $act,
        RegistrationKey $key,
        ?string $reason,
        Actor $actor,
        bool $preview = false,
    ): array {
        $input = ['event_id' => $key->eventId, 'code' => $key->code];
        if ($reason !== null) {
            $input['reason'] = $reason;
        }
        if ($preview) {
            $input['preview'] = true;
        }
        /** @var array{changed: bool, status: string, over_capacity: bool, seats: list<array<string, mixed>>} $result */
        $result = ($this->runner ?? Runner::current())->run(self::useCase($act), $input, $actor);

        return $result;
    }

    private static function useCase(StatusAct $act): StatusChange
    {
        return match ($act) {
            StatusAct::Admit => new AdmitRegistration(),
            StatusAct::Withdraw => new WithdrawRegistration(),
            StatusAct::Remove => new RemoveRegistration(),
            StatusAct::Restore => new RestoreRegistration(),
        };
    }
}
