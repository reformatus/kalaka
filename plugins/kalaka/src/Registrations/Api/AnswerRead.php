<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Money;

/**
 * One stored answering act of a registration (a fact): which request, what answer, who and when. Its `id` is the
 * key the "answered" mail will take, through `subject()`. A new one is built with id 0.
 */
final readonly class AnswerRead
{
    public function __construct(
        public int $id,
        public RegistrationKey $registration,
        public string $request,
        public bool $approved,
        public ?Money $amount,
        public ?string $note,
        public \DateTimeImmutable $answeredAt,
        public ?int $answeredBy,
    ) {
    }

    /** The record an action mail keys itself by: every answering act has its own. */
    public function subject(): Subject
    {
        return new Subject('answer', $this->registration->eventId, (string) $this->id);
    }
}
