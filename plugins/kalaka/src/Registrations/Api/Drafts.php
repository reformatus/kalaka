<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Forms\Api\AnswerSet;
use Kalaka\Forms\Api\DraftClaimChoice;
use Kalaka\Forms\Api\FormDraft;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\ClaimDraft;
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
    public function __construct(
        private readonly ?Runner $runner = null,
        private readonly DraftRepository $drafts = new DraftRepository(),
    ) {
    }

    /**
     * What the form opens with for the person: a logged-in person's own draft (saved as they go), with a
     * guest draft of the form under the cookie's token waiting for their choice; a guest's guest draft
     * (never saved as they type). An empty one when there is none.
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

        return new FormDraft($own->answers, true, $guest !== null);
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
     * @throws PermissionDenied for anyone not logged in
     * @throws \Kalaka\Foundation\Api\Error\KalakaException
     */
    public function save(int $eventId, int $formId, int $version, AnswerSet $answers, int $page, Actor $actor): void
    {
        if (!$actor instanceof AccountActor) {
            $error = new PermissionDenied();
            throw $error;
        }
        $this->runner()->run(new SaveDraft($answers), [
            'event_id' => $eventId,
            'form_id' => $formId,
            'form_version' => $version,
            'page' => min($page, 65535),
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
     * The logged-in person claims the guest draft under the token.
     *
     * @throws \Kalaka\Foundation\Api\Error\KalakaException
     */
    public function claim(int $formId, string $token, AccountActor $actor): DraftClaimOutcome
    {
        $outcome = $this->runner()->run(
            new ClaimDraft(),
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

    private function runner(): Runner
    {
        return $this->runner ?? Runner::current();
    }
}
