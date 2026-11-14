<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\AttachRegistration;

/** Where the attach mail's link is followed (Portal's `AttachHandler`): `kalaka-portal/attach-registration`. */
final class RegistrationAttach
{
    public function __construct(private readonly ?Runner $runner = null)
    {
    }

    /**
     * Attaches the registration the link names to the logged-in account, when the link is its current attach
     * link for that account and it has no owner yet.
     *
     * @return bool whether the account owns the registration now
     * @throws \Kalaka\Foundation\Api\Error\PermissionDenied a link for another account, or not the current one
     * @throws \Kalaka\Foundation\Api\Error\NotFound no such registration
     * @throws \Kalaka\Foundation\Api\Error\KalakaException anything the run throws
     */
    public function attach(string $link, int $userId): bool
    {
        return ($this->runner ?? Runner::current())->run(
            new AttachRegistration(),
            ['link' => $link],
            new AccountActor($userId)
        ) === true;
    }
}
