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
 * answerable only once it is `registered`).
 *
 * The requests come from the `RequestSource`s modules contribute; with none, every read is empty and costs no
 * statement. With some, `states()` costs two (the field values and the answer rows) and `awaits()` one (the field
 * values).
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
     * The registration's requests that need an answer now, each with whether its source says it is answered and
     * its latest answer record. A plain read.
     *
     * @return list<RequestState>
     */
    public function states(RegistrationKey $registration): array
    {
        [$owned, $rows, $values] = $this->read($registration);
        $latest = [];
        foreach ($rows as $answer) {
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
     * The requests the registration has now, keyed by id with their source, every answer row of it in the order
     * they were given, and its field values. With no source, or no request, nothing more is read.
     *
     * @return array{
     *     0: array<string, array{0: Request, 1: RequestSource}>,
     *     1: list<AnswerRead>,
     *     2: array<string, string>
     * }
     */
    private function read(RegistrationKey $registration): array
    {
        $sources = $this->sources ?? Contributions::current()->requestSources();
        if ($sources === []) {
            return [[], [], []];
        }

        $values = [];
        foreach ($this->values->forRegistration($registration) as $value) {
            $values[$value->fieldKey] = $value->value;
        }
        $owned = $this->requestsOf($registration, $values, $sources);
        if ($owned === []) {
            return [[], [], $values];
        }

        return [$owned, $this->answers->forRegistration($registration), $values];
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
     * per-request-source SQL predicate seam, after opening).
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
     * When the registration was last answered (of the requests it has now): the time the due clock counts from
     * beside the moment it last became `registered`; null when none is answered. Only an answer whose yes or no,
     * or amount, differs from the request's answer before it counts; a note added alone is an answer too (it
     * mails) but does not move the due date. [UNAPPROVED N84]
     */
    public function lastAnsweredAt(RegistrationKey $registration): ?\DateTimeImmutable
    {
        [$owned, $rows] = $this->read($registration);
        $last = null;
        $before = [];
        foreach ($rows as $answer) {
            if (!isset($owned[$answer->request])) {
                continue;
            }
            $previous = $before[$answer->request] ?? null;
            $before[$answer->request] = $answer;
            $changed = $previous === null || $previous->approved !== $answer->approved
                || ($previous->amount === null || $answer->amount === null
                    ? $previous->amount !== $answer->amount
                    : !$previous->amount->isEqualTo($answer->amount));
            if ($changed && ($last === null || $answer->answeredAt > $last)) {
                $last = $answer->answeredAt;
            }
        }

        return $last;
    }
}
