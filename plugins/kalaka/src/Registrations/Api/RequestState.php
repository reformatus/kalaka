<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * A request of a registration, whether its source says it is answered (read from the source's answer field: the
 * field is the truth), and the latest answer record of the act, if any: who, when, the note. A request not
 * answered awaits one; an answer of 0 Ft or "no" is an answer. [UNAPPROVED N1] [UNAPPROVED N136]
 */
final readonly class RequestState
{
    public function __construct(
        public Request $request,
        public RequestSource $source,
        public bool $answered,
        public ?AnswerRead $answer,
    ) {
    }

    public function awaits(): bool
    {
        return !$this->answered;
    }
}
