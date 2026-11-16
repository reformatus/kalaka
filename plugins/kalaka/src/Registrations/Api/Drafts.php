<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Forms\Api\AnswerSet;
use Kalaka\Forms\Api\DraftClaimChoice;
use Kalaka\Forms\Api\FormDraft;
use Kalaka\Forms\Api\Forms;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\ClaimDraft;
use Kalaka\Registrations\Draft\Draft;
use Kalaka\Registrations\Draft\DraftRepository;
use Kalaka\Registrations\HandOverPrefill;
use Kalaka\Registrations\ResolveDraftClaim;
use Kalaka\Registrations\SaveDraft;

/**
 * Drafts of registration forms (S74, owner ruling 2026-11-14), for the event form's host and the handoff:
 * what a form opens with, the autosave, a link's or the quiz's handoff as a guest draft, its claim by a
 * logged-in person and the person's choice when it meets their own draft. Reads run outside any
 * transaction; every write is a use case.
 */
final class Drafts
{
    /** @param ?Forms $forms made when first needed: `Forms`' own default host makes a `Drafts` */
    public function __construct(
        private readonly ?Runner $runner = null,
        private readonly DraftRepository $drafts = new DraftRepository(),
        private ?Forms $forms = null,
    ) {
    }

    /**
     * What the form opens with for the person: a logged-in person's own draft (saved as they go), with a
     * guest draft of the form under the cookie's token waiting for their choice; a guest's guest draft
     * (never saved as they type). An empty one when there is none.
     *
     * The guest draft waits for the person's choice (`claimConflict`) only when it sets a value their draft
     * does not hold (`linkChanges()`); the `wp` request's claim settles it otherwise, before the render.
     * `claims` is the draft's claim count, which the autosave carries back (`SaveDraft`).
     *
     * @param ?string $claimToken the token of the request's cookie
     */
    public function opening(int $eventId, int $formId, ?int $userId, ?string $claimToken): FormDraft
    {
        $guest = null;
        if ($claimToken !== null) {
            $found = $this->drafts->ofClaim(DraftClaim::hash($claimToken));
            $now = $this->runner()->clock()->now();
            if (
                $found !== null && $found->userId === null && $found->formId === $formId
                && $found->expiresAt !== null && $found->expiresAt > $now
            ) {
                $guest = $found;
            }
        }
        if ($userId === null) {
            return new FormDraft($guest === null ? new AnswerSet() : $guest->answers, false, false);
        }
        $own = $this->drafts->ofUser($eventId, $formId, $userId);
        if ($own === null) {
            return new FormDraft($guest === null ? new AnswerSet() : $guest->answers, true, false);
        }
        $conflict = $guest !== null && $this->linkChanges($formId, $own, $guest) !== [];

        return new FormDraft($own->answers, true, $conflict, $own->claims);
    }

    /**
     * The keys whose value the guest draft (a link's or the quiz's answers) sets differently from the
     * person's own draft, compared as the form's latest version would store them (`Forms::changedKeys()`).
     * Empty when the link changes nothing: no choice to make. Every key of the guest draft when the form is
     * gone. Not shown to anyone yet; the popup's look is the owner's (S74).
     *
     * @return list<string>
     */
    public function linkChanges(int $formId, Draft $own, Draft $guest): array
    {
        $form = $this->forms()->form($formId);
        if ($form === null) {
            return array_map('strval', array_keys($guest->answers->answers));
        }

        return $this->forms()->changedKeys($this->forms()->definition($form), $own->answers, $guest->answers);
    }

    /** Whether a guest draft is kept under the token. */
    public function holds(string $claimToken): bool
    {
        $found = $this->drafts->ofClaim(DraftClaim::hash($claimToken));

        return $found !== null && $found->userId === null;
    }

    /**
     * The autosave: the answers as the logged-in person's draft of the form.
     *
     * @param int $claims the draft's claim count the page opened with (`FormDraft::$claims`)
     * @throws PermissionDenied for anyone not logged in
     * @throws \Kalaka\Foundation\Api\Error\VersionConflict when a claim changed the draft since the page opened
     * @throws \Kalaka\Foundation\Api\Error\KalakaException
     */
    public function save(
        int $eventId,
        int $formId,
        int $version,
        AnswerSet $answers,
        int $page,
        int $claims,
        Actor $actor
    ): void {
        if (!$actor instanceof AccountActor) {
            $error = new PermissionDenied();
            throw $error;
        }
        $this->runner()->run(new SaveDraft($answers), [
            'event_id' => $eventId,
            'form_id' => $formId,
            'form_version' => $version,
            'page' => min($page, 65535),
            'claims' => $claims,
        ], $actor);
    }

    /**
     * Keeps a link's or the quiz's answers as a guest draft under a new claim token, replacing the earlier
     * one of the same browser, and returns the token for the cookie.
     *
     * @param ?string $earlierToken the token of the request's cookie
     * @throws \Kalaka\Foundation\Api\Error\KalakaException
     */
    public function handOver(
        int $eventId,
        int $formId,
        int $version,
        AnswerSet $answers,
        ?string $earlierToken,
        Actor $actor
    ): string {
        $token = DraftClaim::generate();
        $input = [
            'event_id' => $eventId,
            'form_id' => $formId,
            'form_version' => $version,
            'claim_hash' => DraftClaim::hash($token),
        ];
        if ($earlierToken !== null) {
            $input['replaces'] = DraftClaim::hash($earlierToken);
        }
        $this->runner()->run(new HandOverPrefill($answers), $input, $actor);

        return $token;
    }

    /**
     * The logged-in person claims the guest draft under the token: it becomes their draft, or, against their
     * own, waits for their choice when it changes a value (`Conflict`) and is dropped when it does not
     * (`Settled`, keep_draft).
     *
     * @throws \Kalaka\Foundation\Api\Error\KalakaException
     */
    public function claim(int $formId, string $token, AccountActor $actor): DraftClaimOutcome
    {
        // The form is read before the run (`Forms` reads posts, never inside a transaction).
        $form = $this->forms()->form($formId);
        $definition = $form === null ? null : $this->forms()->definition($form);
        $outcome = $this->runner()->run(
            new ClaimDraft($definition, $this->forms()),
            ['form_id' => $formId, 'claim_hash' => DraftClaim::hash($token)],
            $actor
        );

        return $outcome instanceof DraftClaimOutcome ? $outcome : DraftClaimOutcome::Gone;
    }

    /**
     * The person's choice between the guest draft under the token and their own draft.
     *
     * @throws PermissionDenied for anyone not logged in
     * @throws \Kalaka\Foundation\Api\Error\KalakaException
     */
    public function resolve(int $formId, string $token, DraftClaimChoice $choice, Actor $actor): void
    {
        if (!$actor instanceof AccountActor) {
            $error = new PermissionDenied();
            throw $error;
        }
        $this->runner()->run(
            new ResolveDraftClaim(),
            ['form_id' => $formId, 'claim_hash' => DraftClaim::hash($token), 'choice' => $choice->value],
            $actor
        );
    }

    private function forms(): Forms
    {
        return $this->forms ??= new Forms();
    }

    private function runner(): Runner
    {
        return $this->runner ?? Runner::current();
    }
}
