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
 * The seven ways in: the facts a condition reads besides the answers (`factTypes()`, `facts()`), the
 * access refusals (`refusals()`, `window()`), the scope a field key must be unique in (`keyProblem()`),
 * the values the form opens with (`prefill()`), the person's draft (`draft()`, `saveDraft()`,
 * `resolveDraftClaim()`), what a submit does (`submitToken()`, `submit()`), what the after-submit section
 * reads (`afterSubmitTypes()`), and whether the form needs an account (`accountStep()`).
 */
interface FormHost
{
    /**
     * The keys besides its answers that a form's conditions may read, and their types: the parse checks
     * every condition against them.
     *
     * @return array<string, ValueType>
     */
    public function factTypes(): array;

    /**
     * The values of `factTypes()` for the form; null when what the form belongs to is gone (the form's
     * page then shows it unavailable, and its routes answer `NotFound`).
     */
    public function facts(Form $form): ?ValueContext;

    /**
     * What keeps the form from being used now, in the order the page shows them (the first one). Pure:
     * the caller passes the moment, the person's roles and whether the form's password was given.
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
     * The person's draft the form opens with (S74), read when the page renders, outside any transaction:
     * its answers are laid over `prefill()`'s. An empty `FormDraft` when there is none.
     */
    public function draft(Form $form): FormDraft;

    /**
     * Keeps the answers as the person's draft of the form (S74, the autosave), as a use case. Only a
     * logged-in person has a draft that is saved; anyone else is refused.
     *
     * @param int $version the form version the answers were given against
     * @param int $claims the draft's claim count the page opened with (`FormDraft::$claims`)
     * @throws \Kalaka\Foundation\Api\Error\VersionConflict when a claim changed the draft since
     * @throws \Kalaka\Foundation\Api\Error\KalakaException
     */
    public function saveDraft(
        Form $form,
        int $version,
        AnswerSet $answers,
        int $page,
        int $claims,
        Actor $actor
    ): void;

    /**
     * Settles a link's answers against the person's own draft with the person's choice (S74).
     *
     * @throws \Kalaka\Foundation\Api\Error\KalakaException
     */
    public function resolveDraftClaim(Form $form, DraftClaimChoice $choice, Actor $actor): void;

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
