<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Registrations\FieldValue\FieldValue;
use Kalaka\Registrations\FieldValue\FieldValueRepository;
use Kalaka\Registrations\RegistrationRepository;
use Kalaka\Registrations\Submission\SubmissionRepository;

/**
 * Reads of a registration outside any use case, for documents rendered from current data: its status, the
 * form of its first submission and its field values. Never the secret: a mail's link is `SecretLinks::url()`.
 */
final class RegistrationLookup
{
    public function __construct(
        private readonly RegistrationRepository $registrations = new RegistrationRepository(),
        private readonly SubmissionRepository $submissions = new SubmissionRepository(),
        private readonly FieldValueRepository $values = new FieldValueRepository(),
    ) {
    }

    /** @return ?RegistrationRecord null when there is no such registration or it has no first submission */
    public function find(RegistrationKey $key): ?RegistrationRecord
    {
        $registration = $this->registrations->find($key);
        $first = $this->submissions->find(['eventId' => $key->eventId, 'code' => $key->code, 'number' => 1]);
        if ($registration === null || $first === null) {
            return null;
        }

        $values = [];
        foreach ($this->values->forRegistration($key) as $value) {
            /** @var FieldValue $value */
            $values[$value->fieldKey] = $value->value;
        }

        return new RegistrationRecord($key, $registration->status, $first->formId, $first->formVersion, $values);
    }
}
