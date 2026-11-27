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
 * values). [UNAPPROVED N1] [UNAPPROVED N2]
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
                $latest[$id] ?? null,
                $values
            );
        }

        return $states;
    }

    /**
     * Whether the registration has a request its source says is not answered (from its answer field); false for a
     * withdrawn or removed registration.
     */
    public function awaits(RegistrationKey $registration, RegistrationStatus $status): bool
    {
        return $this->awaitsOf($registration->eventId, [$registration->code => $status])[$registration->code];
    }

    /**
     * "Awaits an answer" for several of one event's registrations at once, by the one rule of `awaits()`: the
     * cost is fixed whatever the count: with no source, or when every registration is withdrawn or removed, no
     * statement; otherwise one, the field values, which hold every answer. (Shapes) grid rows carry
     * awaits_answer: bool, PHP-computed per page; not filterable, sortable or summable (alt: a
     * per-request-source SQL predicate seam, after opening). [UNAPPROVED N351]
     *
     * @param array<string, RegistrationStatus> $statusByCode the registrations' codes with their statuses
     * @return array<string, bool> by code, every given code
     */
    public function awaitsOf(int $eventId, array $statusByCode): array
    {
        $awaits = array_fill_keys(array_map('strval', array_keys($statusByCode)), false);
        $sources = $this->sources ?? Contributions::current()->requestSources();
        $live = [];
        foreach ($statusByCode as $code => $status) {
            if ($status !== RegistrationStatus::Withdrawn && $status !== RegistrationStatus::Removed) {
                $live[] = (string) $code;
            }
        }
        if ($sources === [] || $live === []) {
            return $awaits;
        }

        $values = [];
        foreach ($this->values->forRegistrations($eventId, $live) as $value) {
            $values[$value->registration->code][$value->fieldKey] = $value->value;
        }
        foreach ($live as $code) {
            $key = new RegistrationKey($eventId, $code);
            foreach ($this->requestsOf($key, $values[$code] ?? [], $sources) as [$request, $source]) {
                if (!$source->isAnswered($request, $values[$code] ?? [])) {
                    $awaits[$code] = true;
                    break;
                }
            }
        }

        return $awaits;
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
