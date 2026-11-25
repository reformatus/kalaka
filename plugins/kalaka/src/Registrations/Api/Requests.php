<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Registrations\Answer\AnswerRepository;
use Kalaka\Registrations\FieldValue\FieldValueRepository;

/**
 * The reads behind "answer a request" (money.md, "Sponsor and church support"; foundation.md, "Facts, decisions
 * and computed values"): a registration's requests with their latest answers, and the computed "awaits an
 * answer". Nothing is stored for the latter: a registration awaits an answer while it has a request its source
 * says is not answered, read from the source's own answer field, whatever the answer says ("no", or an award of
 * 0 Ft, is an answer). The field is the truth; the `kalaka_answers` rows record the act (who, when, the note, the
 * mail's key) and decide nothing else. A withdrawn or removed registration awaits nothing; a waitlisted one
 * does (the fact stays computed, but nothing shows it while the registration is inactive, and the request is
 * answerable only once it is `registered`). [UNAPPROVED N10] [UNAPPROVED N136]
 *
 * The requests come from the `RequestSource`s modules contribute; with none, every read is empty and costs no
 * statement. With some, `states()` costs two (the field values and the answer rows) and `awaits()` one (the field
 * values); the grid asks in SQL instead (`awaitingPredicate()`). [UNAPPROVED N1] [UNAPPROVED N2]
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
        $owned = $this->requestsOf($registration, $values, $sources);
        if ($owned === []) {
            return [];
        }

        $latest = [];
        foreach ($this->answers->forRegistration($registration) as $answer) {
            $latest[$answer->request] = $answer;
        }
        $states = [];
        foreach ($owned as $id => [$request, $source]) {
            $states[] = new RequestState(
                $request,
                $source,
                $source->isAnswered($request, $values),
                $latest[$id] ?? null
            );
        }

        return $states;
    }

    /**
     * Whether the registration has a request its source says is not answered (from its answer field); false for a
     * withdrawn or removed registration. One statement (the field values, which hold every answer); none with no
     * source or for a withdrawn or removed registration.
     */
    public function awaits(RegistrationKey $registration, RegistrationStatus $status): bool
    {
        $sources = $this->sources ?? Contributions::current()->requestSources();
        if (
            $sources === []
            || $status === RegistrationStatus::Withdrawn
            || $status === RegistrationStatus::Removed
        ) {
            return false;
        }

        $values = [];
        foreach ($this->values->forRegistration($registration) as $value) {
            $values[$value->fieldKey] = $value->value;
        }
        foreach ($this->requestsOf($registration, $values, $sources) as [$request, $source]) {
            if (!$source->isAnswered($request, $values)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Awaits an answer" as a condition on the registration row `r`, for the admin grid's column (filter, sort and
     * count in SQL, over the whole view, never one page): the registration is `registered` and some source has a
     * request awaiting an answer (`RequestSource::awaitingPredicate()`); false for every other status, so a
     * waitlisted registration shows no awaiting (it waits until admission), and false with no source. The rule is
     * the one of `awaits()` for a `registered` registration. No statement is run.
     * (Access) any view may list the column; it tells that something awaits, not what. [UNAPPROVED N668]
     * (Shapes) a core-derived grid column over a per-source SQL predicate. [UNAPPROVED N669]
     *
     * @param string $fieldValues the participant field values table
     */
    public function awaitingPredicate(int $eventId, string $fieldValues): SqlPredicate
    {
        $sources = $this->sources ?? Contributions::current()->requestSources();
        /** @var list<literal-string> $parts */
        $parts = [];
        $args = [];
        foreach ($sources as $source) {
            $predicate = $source->awaitingPredicate($eventId, $fieldValues);
            if ($predicate !== null) {
                $parts[] = '(' . $predicate->sql . ')';
                array_push($args, ...$predicate->args);
            }
        }
        if ($parts === []) {
            return new SqlPredicate('0 = 1');
        }

        return new SqlPredicate("r.status = 'registered' AND (" . implode(' OR ', $parts) . ')', $args);
    }

    /**
     * The requests the sources name for a registration, by request ID (the first source to name one owns it).
     *
     * @param array<string, string> $values
     * @param list<RequestSource> $sources
     * @return array<string, array{Request, RequestSource}>
     */
    private function requestsOf(RegistrationKey $registration, array $values, array $sources): array
    {
        $owned = [];
        foreach ($sources as $source) {
            foreach ($source->requestsOf($registration, $values) as $request) {
                $owned[$request->id] ??= [$request, $source];
            }
        }

        return $owned;
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
