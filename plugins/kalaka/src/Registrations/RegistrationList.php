<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

use Kalaka\Foundation\Api\Query\Query;
use Kalaka\Registrations\Api\RegistrationStatus;

/** The registrations of an event with their sorszám, which is computed here and never stored. */
final class RegistrationList extends Query
{
    /**
     * Ordered by `created_at`, then `code`. The sorszám counts from 1 within `registered` and within
     * `waitlisted` in that order; `withdrawn` and `removed` have none.
     *
     * @return list<array{
     *     code: string, status: RegistrationStatus, created_at: string, registered_at: ?string, serial: ?int
     * }>
     */
    public function forEvent(int $eventId): array
    {
        return $this->scope(function () use ($eventId): array {
            $rows = $this->select(
                'registration',
                'SELECT code, status, created_at, registered_at FROM %i WHERE event_id = %d ORDER BY created_at, code',
                [$this->table('kalaka_registrations'), $eventId]
            );

            $counted = [];
            $list = [];
            foreach ($rows as $row) {
                $status = RegistrationStatus::from((string) $row['status']);
                $serial = null;
                if ($status === RegistrationStatus::Registered || $status === RegistrationStatus::Waitlisted) {
                    $serial = $counted[$status->value] = ($counted[$status->value] ?? 0) + 1;
                }
                $list[] = [
                    'code' => (string) $row['code'],
                    'status' => $status,
                    'created_at' => (string) $row['created_at'],
                    'registered_at' => $row['registered_at'],
                    'serial' => $serial,
                ];
            }

            return $list;
        });
    }
}
