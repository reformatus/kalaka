<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Registrations\FieldValue\FieldValueRepository;

/**
 * A registration's current field values, for other modules to read (registrations-and-identity.md,
 * "Submissions and values").
 */
final class FieldValues
{
    public function __construct(private readonly FieldValueRepository $repository = new FieldValueRepository())
    {
    }

    /**
     * The registration's field values, a plain read in one statement.
     *
     * @return array<string, string> field key → the stored value
     */
    public function of(RegistrationKey $registration): array
    {
        $values = [];
        foreach ($this->repository->forRegistration($registration) as $value) {
            $values[$value->fieldKey] = $value->value;
        }

        return $values;
    }
}
