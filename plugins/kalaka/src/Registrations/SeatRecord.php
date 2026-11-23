<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\SeatAct;

/** The stored seat (`kalaka_seats`): one place in one pool, held by one registration. */
final class SeatRecord
{
    public function __construct(
        public readonly RegistrationKey $registration,
        public readonly string $pool,
        public readonly \DateTimeImmutable $takenAt,
        public readonly SeatAct $act,
        public int $version = 0,
    ) {
    }
}
