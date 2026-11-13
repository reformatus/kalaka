<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Submission;

use Kalaka\Foundation\Api\Query\Query;

/** Whether a form has submissions, inside its event's range of the primary key. */
final class FormSubmissionQuery extends Query
{
    public function exists(int $eventId, int $formId): bool
    {
        return $this->scope(fn (): bool => $this->select(
            'submission',
            'SELECT 1 AS found FROM %i WHERE event_id = %d AND form_id = %d LIMIT 1',
            [$this->table('kalaka_submissions'), $eventId, $formId]
        ) !== []);
    }
}
