<?php

declare(strict_types=1);

namespace Kalaka\Registrations\FieldValue;

use Kalaka\Registrations\Api\RegistrationKey;

/** One answered field of a registration; `value` is the field type's stored form. */
final class FieldValue
{
    public function __construct(
        public readonly RegistrationKey $registration,
        public readonly string $fieldKey,
        public string $value,
        public int $version = 0,
    ) {
    }
}
