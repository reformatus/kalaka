<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Registrations\FieldValue\FieldValue;
use Kalaka\Registrations\FieldValue\FieldValueRepository;
use Kalaka\Registrations\RegistrationRepository;
use Kalaka\Registrations\Submission\SubmissionRepository;

/**
 * Reads of a registration outside any use case, for documents rendered from current data. The secret link
 * is handed out only as its finished URL, built here, the one module that reads the stored secret
 * (registrations-and-identity.md, "Access": secret links are stored readable so every email can carry one).
 */
final class RegistrationRead
{
    /** The query arguments of the secret link. */
    public const LINK_EVENT = 'kalaka_event';
    public const LINK_CODE = 'kalaka_code';
    public const LINK_SECRET = 'kalaka_link';

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

    /**
     * The registration's secret link: a secret, so it is never logged, audited in clear or put in an
     * exception. Null when there is no such registration.
     * // [UNAPPROVED N15] a provisional link shape (the site's home with three query arguments) until the
     * // portal slice gives the link its page.
     */
    public function linkUrl(RegistrationKey $key): ?string
    {
        $registration = $this->registrations->find($key);
        if ($registration === null) {
            return null;
        }

        return add_query_arg(
            [
                self::LINK_EVENT => (string) $key->eventId,
                self::LINK_CODE => rawurlencode($key->code),
                self::LINK_SECRET => $registration->secret,
            ],
            home_url('/')
        );
    }
}
