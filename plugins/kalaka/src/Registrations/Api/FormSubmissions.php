<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Registrations\Submission\FormSubmissionQuery;

/**
 * What Forms may ask about a form's submissions: whether it has any. A form with submissions is never
 * deleted permanently and never moves to another event. A plain read; it may run anywhere.
 */
final class FormSubmissions
{
    public function __construct(private readonly FormSubmissionQuery $query = new FormSubmissionQuery())
    {
    }

    public function exist(int $eventId, int $formId): bool
    {
        return $this->query->exists($eventId, $formId);
    }
}
