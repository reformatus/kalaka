<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\RegistrationRepository;

/** What other modules may do with a registration row: read it, or lock it. */
final class Registrations
{
    public function __construct(private readonly RegistrationRepository $repository = new RegistrationRepository())
    {
    }

    /** A plain read, without a lock; null when there is no such registration. */
    public function find(RegistrationKey $key): ?RegistrationRead
    {
        $registration = $this->repository->find($key);

        return $registration === null
            ? null
            : new RegistrationRead($registration->key, $registration->status, $registration->registeredAt);
    }

    /**
     * Reads the registration `FOR UPDATE` at `LockLevel::Registration`, so the run holds it
     * (`Context::holdsRegistration()`).
     *
     * @return bool false when there is no such registration
     * @throws \Kalaka\Foundation\Api\Error\SystemFailure when the lock order forbids the lock now
     */
    public function lock(RegistrationKey $key, Context $context): bool
    {
        return $this->repository->findForUpdate($key, $context) !== null;
    }
}
