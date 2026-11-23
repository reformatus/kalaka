<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Registrations\Answer\AnswerRepository;
use Kalaka\Registrations\FieldValue\FieldValueRepository;

/**
 * The reads behind "answer a request" (money.md, "Sponsor and church support"; foundation.md, "Facts, decisions
 * and computed values"): a registration's requests with their latest answers, and the computed "awaits an
 * answer". Nothing is stored for the latter: a registration awaits an answer while it has a request no answer
 * has been given for, whatever the answer says ("no", or an award of 0 Ft, is an answer). A withdrawn or removed
 * registration awaits nothing; a waitlisted one does. [UNAPPROVED N10]
 *
 * The requests come from the `RequestSource`s modules contribute; with none, every read is empty and costs no
 * statement. With some, a read costs two: the field values and the answers. [UNAPPROVED N1] [UNAPPROVED N2]
 */
final class Requests
{
    /** The per-event action that lets a grant holder answer a request; full access implies it. */
    public const ANSWER = 'kalaka_answer_request';

    /** @var ?list<RequestSource> */
    private readonly ?array $sources;

    /** @param ?list<RequestSource> $sources null: the sources modules contributed at boot */
    public function __construct(
        ?array $sources = null,
        private readonly FieldValueRepository $values = new FieldValueRepository(),
        private readonly AnswerRepository $answers = new AnswerRepository(),
    ) {
        $this->sources = $sources;
    }

    /**
     * The registration's requests that need an answer now, each with its latest answer. A plain read.
     *
     * @return list<RequestState>
     */
    public function states(RegistrationKey $registration): array
    {
        $sources = $this->sources ?? Contributions::current()->requestSources();
        if ($sources === []) {
            return [];
        }

        $values = [];
        foreach ($this->values->forRegistration($registration) as $value) {
            $values[$value->fieldKey] = $value->value;
        }
        $owned = [];
        foreach ($sources as $source) {
            foreach ($source->requestsOf($registration, $values) as $request) {
                $owned[$request->id] ??= [$request, $source];
            }
        }
        if ($owned === []) {
            return [];
        }

        $latest = [];
        foreach ($this->answers->forRegistration($registration) as $answer) {
            $latest[$answer->request] = $answer;
        }
        $states = [];
        foreach ($owned as $id => [$request, $source]) {
            $states[] = new RequestState($request, $source, $latest[$id] ?? null);
        }

        return $states;
    }

    /** Whether the registration has a request with no answer; false for a withdrawn or removed registration. */
    public function awaits(RegistrationKey $registration, RegistrationStatus $status): bool
    {
        if ($status === RegistrationStatus::Withdrawn || $status === RegistrationStatus::Removed) {
            return false;
        }
        foreach ($this->states($registration) as $state) {
            if ($state->awaits()) {
                return true;
            }
        }

        return false;
    }

    /**
     * When the registration was last answered (of the requests it has now): the time the due clock will count
     * from beside the moment it last became `registered`; null when none is answered.
     */
    public function lastAnsweredAt(RegistrationKey $registration): ?\DateTimeImmutable
    {
        $last = null;
        foreach ($this->states($registration) as $state) {
            $at = $state->answer?->answeredAt;
            if ($at !== null && ($last === null || $at > $last)) {
                $last = $at;
            }
        }

        return $last;
    }
}
