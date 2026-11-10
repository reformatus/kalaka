<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * A read of one registration for documents rendered from current data (a mail, a portal page): its status,
 * the form of its first submission (the registration slot's form) and its current field values. It never
 * holds the secret.
 */
final readonly class RegistrationRecord
{
    /** @param array<string, string> $values field key → the stored value, as the field type stored it */
    public function __construct(
        public RegistrationKey $key,
        public RegistrationStatus $status,
        public int $formId,
        public int $formVersion,
        public array $values,
    ) {
    }
}
