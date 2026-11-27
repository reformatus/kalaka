<?php

declare(strict_types=1);

namespace Kalaka\Foundation\ActKeys;

use Kalaka\Registrations\Api\RegistrationKey;

/** A retry key a staff act has used on a registration, with the act's input hash and its result. [UNAPPROVED N1140] */
final class ActKey
{
    public function __construct(
        public readonly RegistrationKey $registration,
        public readonly string $act,
        public readonly string $key,
        public readonly string $inputHash,
        public readonly string $result,
        public readonly \DateTimeImmutable $createdAt,
        public readonly int $id = 0,
    ) {
    }
}
