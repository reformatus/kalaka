<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Evaluation;

use Kalaka\Foundation\Api\Query\Query;
use Kalaka\Registrations\Api\RegistrationKey;

/** Reads the "evaluation failed" flags for the periodic run's retries. */
final class EvaluationFailureQuery extends Query
{
    /**
     * One statement; an empty list issues none.
     *
     * @param list<int> $eventIds
     * @return list<RegistrationKey> ascending by (event, code)
     */
    public function flaggedIn(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        return $this->scope(function () use ($eventIds): array {
            $placeholders = implode(', ', array_fill(0, count($eventIds), '%d'));
            $rows = $this->select(
                'flag',
                'SELECT event_id, code FROM %i WHERE event_id IN (' . $placeholders . ') ORDER BY event_id, code',
                [$this->table('kalaka_evaluation_failures'), ...$eventIds]
            );

            return array_map(
                static fn (array $row): RegistrationKey => new RegistrationKey(
                    (int) $row['event_id'],
                    (string) $row['code']
                ),
                $rows
            );
        });
    }
}
