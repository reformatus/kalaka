<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * A request of a registration and its latest answer, if any. A request with no answer awaits one; an answer of
 * 0 Ft or "no" is an answer. [UNAPPROVED N1]
 */
final readonly class RequestState
{
    public function __construct(
        public Request $request,
        public RequestSource $source,
        public ?AnswerRead $answer,
    ) {
    }

    public function awaits(): bool
    {
        return $this->answer === null;
    }
}
