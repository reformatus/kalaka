<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\UseCase;

use Kalaka\Registrations\Api\RegistrationKey;

interface PortalUseCase extends UseCase
{
    /**
     * The registrations the actor must have access to.
     *
     * @param array<string, mixed> $input
     * @return list<RegistrationKey>
     */
    public function registrations(array $input): array;
}
