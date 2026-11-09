<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Decision;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Query\Query;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Registrations\Api\RegistrationKey;

/** Reads the decisions of one registration. */
final class DecisionQuery extends Query
{
    /**
     * One statement over the registration's rows; the last row of a kind is its current decision.
     *
     * @return array<string, Decision> by kind
     */
    public function currentOf(RegistrationKey $registration): array
    {
        return $this->scope(function () use ($registration): array {
            $rows = $this->select(
                'decision',
                'SELECT * FROM %i WHERE event_id = %d AND code = %s ORDER BY id',
                [$this->table('kalaka_decisions'), $registration->eventId, $registration->code]
            );

            $current = [];
            foreach ($rows as $row) {
                $kind = (string) $row['kind'];
                $current[$kind] = new Decision(
                    $kind,
                    (string) $row['state'],
                    Instant::fromStorage((string) $row['effective_at']),
                    Instant::fromStorage((string) $row['recorded_at']),
                    new Subject(
                        (string) $row['cause_type'],
                        $row['cause_event_id'] === null ? null : (int) $row['cause_event_id'],
                        (string) $row['cause_key'],
                    ),
                );
            }

            return $current;
        });
    }
}
