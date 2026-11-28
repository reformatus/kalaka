<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Actor\SecretLinkActor;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\Status\WithdrawOwnRegistration;

/**
 * Runs the participant's own withdrawal (`kalaka-portal/withdraw-registration`) for Portal, which may not reach
 * the use case class: through the runner, as the secret-link actor of the registration, so the permission, the audit
 * and the evaluation are the use case's. It holds no rule. [UNAPPROVED N660]
 */
final class OwnWithdrawal
{
    public function __construct(private readonly ?Runner $runner = null)
    {
    }

    /**
     * @param bool $preview true: `{allowed, paid, owed_after, retained, surplus}` of the registrant's own payer,
     *     nothing written
     * @return array<string, mixed> `{changed, status}`, or the preview
     * @throws \Kalaka\Foundation\Api\Error\KalakaException as the use case throws it
     */
    public function run(RegistrationKey $key, bool $preview = false): array
    {
        $input = ['event_id' => $key->eventId, 'code' => $key->code];
        if ($preview) {
            $input['preview'] = true;
        }
        /** @var array<string, mixed> $result */
        $result = ($this->runner ?? Runner::current())->run(
            new WithdrawOwnRegistration(),
            $input,
            new SecretLinkActor($key)
        );

        return $result;
    }
}
