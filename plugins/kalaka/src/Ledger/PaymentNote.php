<?php

declare(strict_types=1);

namespace Kalaka\Ledger;

use Kalaka\Registrations\Api\RegistrationKey;

/** The free text of one manual ledger line. It lives where erasure can reach it. */
final class PaymentNote
{
    public function __construct(
        public readonly RegistrationKey $registration,
        public readonly int $lineId,
        public readonly string $note,
        public int $version = 0,
    ) {
    }
}
