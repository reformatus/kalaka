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
 * says is not answered, read from the source's own answer field (its `AwaitingRule`, the one definition), whatever
 * the answer says ("no", or an award of 0 Ft, is an answer). The field is the truth; the `kalaka_answers` rows record
 * the act (who, when, the note, the mail's key) and decide nothing else. Only a `registered` registration awaits
 * an answer: a waitlisted, withdrawn or removed one awaits nothing, and a waitlisted one awaits again by itself
 * at admission, when its request becomes answerable. [UNAPPROVED N10] [UNAPPROVED N136] [UNAPPROVED N690]
 *
 * The requests come from the `RequestSource`s modules contribute; with none, every read is empty and costs no
 * statement. With some, `states()` costs two (the field values and the answer rows) and `awaits()` one (the field
 * values); the grid asks in SQL instead (`awaitingCondition()`). [UNAPPROVED N1] [UNAPPROVED N2]
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
     * Whether the registration awaits an answer: it is `registered` and some source's `AwaitingRule` holds over its
     * field values, evaluated in PHP with `AwaitingRule::matches()`. The rule is the one definition
     * `awaitingCondition()` compiles to SQL, with the same `registered` gate, so the fact, the grid column and the
     * detail panel mean one thing; the panel's `states()` read the sources' `requestsOf()` and `isAnswered()`,
     * which the rule must mean exactly. A waitlisted registration awaits nothing and awaits again by itself at
     * admission. One statement (the field values, which hold every answer); none with no source or for a
     * registration that is not registered. [UNAPPROVED N690]
     */
    public function awaits(RegistrationKey $registration, RegistrationStatus $status): bool
    {
        $sources = $this->sources ?? Contributions::current()->requestSources();
        if (
            $sources === []
            || $status !== RegistrationStatus::Registered
        ) {
            return false;
        }

        $values = [];
        foreach ($this->values->forRegistration($registration) as $value) {
            $values[$value->fieldKey] = $value->value;
        }
        foreach ($sources as $source) {
            if ($source->awaitingRule($registration->eventId)?->matches($values) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Awaits an answer" as a condition on the registration row `r`, for the admin grid's column (filter, sort and
     * count in SQL, over the whole view, never one page): the registration is `registered` and some source's
     * `AwaitingRule` holds (`RequestSource::awaitingRule()`); false for every other status, so a waitlisted
     * registration shows no awaiting (it waits until admission), and false with no rule. Core alone writes the SQL:
     * each test is an `EXISTS` or `NOT EXISTS` over the field values table, every value a placeholder argument.
     * The rule is the one `awaits()` evaluates in PHP, for a `registered` registration. No statement is run.
     * (Access) any view may list the column; it tells that something awaits, not what. [UNAPPROVED N668]
     * (Shapes) a core-derived grid column over the sources' declarative rules. [UNAPPROVED N669]
     *
     * @param string $fieldValues the participant field values table
     * @return array{0: literal-string, 1: list<string|int>} the condition and its placeholder arguments
     */
    public function awaitingCondition(int $eventId, string $fieldValues): array
    {
        $sources = $this->sources ?? Contributions::current()->requestSources();
        /** @var list<literal-string> $parts */
        $parts = [];
        $args = [];
        foreach ($sources as $source) {
            $rule = $source->awaitingRule($eventId);
            if ($rule === null) {
                continue;
            }
            $groups = [];
            foreach ($rule->groups as $group) {
                $tests = [];
                foreach ($group as $test) {
                    [$sql, $testArgs] = self::compile($test, $fieldValues);
                    $tests[] = $sql;
                    array_push($args, ...$testArgs);
                }
                $groups[] = '(' . implode(' AND ', $tests) . ')';
            }
            $parts[] = '(' . implode(' OR ', $groups) . ')';
        }
        if ($parts === []) {
            return ['0 = 1', []];
        }

        return ["r.status = 'registered' AND (" . implode(' OR ', $parts) . ')', $args];
    }

    /**
     * One field test as an `EXISTS` or `NOT EXISTS` over the field values table, in MariaDB 10.3 SQL. The value is
     * compared byte for byte (`BINARY`, no collation, no trailing-space padding) and "filled" is a length above zero,
     * so the SQL means what `FieldTest::matches()` means: case, accents and spaces all count.
     *
     * @return array{0: literal-string, 1: list<string>}
     */
    private static function compile(FieldTest $test, string $fieldValues): array
    {
        $exists = 'EXISTS (SELECT 1 FROM %i f WHERE f.event_id = r.event_id AND f.code = r.code'
            . ' AND f.field_key = %s AND ';
        $base = [$fieldValues, $test->key];
        $in = implode(', ', array_fill(0, count($test->values), '%s'));

        return match ($test->kind) {
            FieldTestKind::Filled => [$exists . 'LENGTH(f.value) > 0)', $base],
            FieldTestKind::Empty => ['NOT ' . $exists . 'LENGTH(f.value) > 0)', $base],
            FieldTestKind::OneOf => [
                $exists . 'BINARY f.value IN (' . $in . '))',
                [...$base, ...$test->values],
            ],
            FieldTestKind::NoneOf => [
                'NOT ' . $exists . 'BINARY f.value IN (' . $in . '))',
                [...$base, ...$test->values],
            ],
        };
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
