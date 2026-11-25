<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Foundation\Api\Actor\Actor;

/**
 * What the form engine (`Forms\Engine`) asks the thing a form belongs to: everything about it that the
 * engine does not know itself. Kaláka's event form (`Forms\EventFormHost`) is the first host. The engine
 * never imports Events, Registrations, ParticipantFields or Admin; it asks here (deptrac: the
 * `Forms Engine` layer). Like `BlockHost`, it lets the engine work without knowing the module behind it.
 *
 * The eight ways in: the facts a condition reads besides the answers (`factTypes()`, `facts()`), the
 * access refusals (`refusals()`, `window()`), the scope a field key must be unique in (`keyProblem()`),
 * the values the form opens with (`prefill()`), the options of the set a request field names
 * (`requestOptions()`), what a submit does (`submitToken()`, `submit()`), what the after-submit section
 * reads (`afterSubmitTypes()`), and whether the form needs an account (`accountStep()`).
 */
interface FormHost
{
    /**
     * The keys besides its answers that a form's conditions may read, and their types: the parse checks
     * every condition against them. Asked for the form, so a host may declare keys that only some forms have.
     *
     * @return array<string, ValueType>
     */
    public function factTypes(Form $form): array;

    /**
     * Of the keys that the conditions of any revision of the form read and `factTypes()` does not declare, the
     * ones that were valid once and are gone now (e.g. a custom point staff removed), with their types. A
     * revision that reads one still parses, and the key reads as empty (none), so the condition is unknown and
     * its branch does not hold. Asked only when a definition is read, never when it is saved.
     *
     * @param list<string> $keys
     * @return array<string, ValueType>
     */
    public function goneTypes(Form $form, array $keys): array;

    /**
     * The values of `factTypes()` for the form; null when what the form belongs to is gone (the form's
     * page then shows it unavailable, and its routes answer `NotFound`).
     */
    public function facts(Form $form): ?ValueContext;

    /**
     * What keeps the form from being used now, in the order the page shows them (the first one). It reads the
     * request's active registration (an additional form acts on it) and the logged-in user, and nothing else
     * from the request: no other cookie or request state; the caller passes the moment, the person's roles and
     * whether the form's password was given.
     *
     * @param list<string> $roles
     * @return list<AccessRefusal>
     */
    public function refusals(Form $form, \DateTimeImmutable $now, array $roles, bool $passwordGiven): array;

    /**
     * The window the form takes answers in, from its opening to its closing instant: for the page's
     * text when `refusals()` gives `OutsideRegistrationWindow`. Either is null when it is not set.
     *
     * @return array{opens: ?\DateTimeImmutable, closes: ?\DateTimeImmutable}
     */
    public function window(Form $form): array;

    /**
     * The first problem of the content of the form's current version against the keys beyond the form
     * that its field keys must agree with; null when there is none.
     *
     * @return array{string, string}|null the problem code and the field key
     */
    public function keyProblem(Form $form, string $content): ?array;

    /**
     * The stored values the form opens with, by field key (events-and-forms.md, "Prefill from a link"),
     * e.g. for an additional form the current field values of the registration it writes to. The engine turns
     * each into its field's answer (`FieldType::prefill()`) and renders the form for those answers. Read
     * when the page renders, outside any transaction.
     *
     * @return array<string, string>
     */
    public function prefill(Form $form): array;

    /**
     * The options of the set a request field names (`RequestFieldType`), for the
     * form: null when the form's event has no such set in request mode (the form is then unusable). Read
     * when the definition is parsed, outside any transaction.
     */
    public function requestOptions(Form $form, int $setId): ?RequestOptions;

    /** A new submit token, issued on every render of the form's page into its hidden field. */
    public function submitToken(): string;

    /**
     * What a submit does: it checks the answers again, stores them, and gives the submit route's answer.
     *
     * @param array<array-key, mixed> $answers field key → the answer as sent
     * @param list<string> $roles
     * @return array<string, mixed> the submit route's answer
     * @throws \Kalaka\Foundation\Api\Error\KalakaException
     */
    public function submit(
        int $formId,
        int $version,
        array $answers,
        string $token,
        array $roles,
        bool $passwordGiven,
        Actor $actor
    ): array;

    /**
     * The keys the after-submit section's conditions and values may read, and their types.
     *
     * @return array<string, ValueType>
     */
    public function afterSubmitTypes(): array;

    /**
     * Whether the form needs an account for someone not logged in (`Required`), or offers one
     * (`Offered`); null when the form has no accounts (then a logged-in person is not the owner either).
     * Reads no current user: the form page, its re-fetch and the account block ask it for anyone.
     */
    public function accountStep(Form $form): ?AccountStep;
}
