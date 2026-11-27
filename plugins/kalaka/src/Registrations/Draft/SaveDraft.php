<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Draft;

use Kalaka\Forms\Api\AnswerSet;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\PublicUseCase;

/**
 * The autosave (S74): keeps the answers as the logged-in person's draft of the form, one per event, form
 * and person, created at the first answer; or, for a guest (owner ruling 2026-11-26), as the guest draft under
 * the claim token's hash (`claim_hash`), the one a link's or the quiz's handoff writes: the first save creates
 * it (and deletes the guest draft of another form the same browser held, `replaces`: one browser holds one),
 * a later one updates it. The
 * answers come through the constructor, already reduced to the form's own keys by the route; they are
 * never input, so they never reach a failed run's audit entry.
 *
 * A save carries the draft's `claims` count its page opened with. When a claim changed the draft since
 * (`ClaimDraft`, `ResolveDraftClaim`), the count differs and the save is refused as a `VersionConflict`:
 * the answers of a page from before the claim never write over what the claim brought. Saves alone never
 * change the count, so two tabs' saves still both go in, the last one winning. A first save, with no draft
 * yet, starts the draft at the count it carries. A guest's draft has a count too (N1106): a guest's choice on a
 * link's answers that changed it (`ResolveGuestHandoff`) raises it, and a save from the page before it is refused.
 */
final class SaveDraft implements PublicUseCase
{
    public function __construct(
        private readonly AnswerSet $answers,
        private readonly DraftRepository $drafts = new DraftRepository(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka-portal/save-draft';
    }

    public function inputSchema(): array
    {
        $hash = ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'];

        return [
            'type' => 'object',
            'properties' => [
                'claim_hash' => $hash,
                'replaces' => $hash,
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'form_id' => ['type' => 'integer', 'minimum' => 1],
                'form_version' => ['type' => 'integer', 'minimum' => 1],
                'page' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 65535],
                'claims' => ['type' => 'integer', 'minimum' => 0],
            ],
            'required' => ['event_id', 'form_id', 'form_version', 'page', 'claims'],
            'additionalProperties' => false,
        ];
    }

    public function registrations(array $input): array
    {
        return [];
    }

    public function execute(array $input, Context $context): null
    {
        $actor = $context->actor();
        if ($actor instanceof GuestActor && isset($input['claim_hash'])) {
            return $this->executeGuest($input, $context);
        }
        if (!$actor instanceof AccountActor) {
            $error = new PermissionDenied();
            throw $error;
        }
        $eventId = (int) $input['event_id'];
        $formId = (int) $input['form_id'];
        $claims = (int) $input['claims'];
        $now = $context->now();

        $draft = $this->drafts->ofUser($eventId, $formId, $actor->userId, $context);
        if ($draft === null) {
            $new = new Draft(
                $eventId,
                $formId,
                (int) $input['form_version'],
                $actor->userId,
                null,
                $this->answers,
                (int) $input['page'],
                $now,
                $now,
                null,
                claims: $claims
            );
            if ($this->drafts->insertNew($new, $context) !== null) {
                return null;
            }
            // Another tab's first save came first: this one updates it.
            $draft = $this->drafts->ofUser($eventId, $formId, $actor->userId, $context);
            if ($draft === null) {
                return null;
            }
        }
        if ($draft->claims !== $claims) {
            // A page from before a claim that changed the draft (S74, pass 2): its answers are not kept.
            $error = new VersionConflict();
            throw $error;
        }
        $draft->formVersion = (int) $input['form_version'];
        $draft->answers = $this->answers;
        $draft->page = (int) $input['page'];
        $draft->updatedAt = $now;
        $this->drafts->upsert($draft, $context);

        return null;
    }

    /**
     * A guest's save: the guest draft of the form under the hash is updated, or created when there is none
     * (the first answer, or one swept or deleted since).
     *
     * @param array<string, mixed> $input
     */
    private function executeGuest(array $input, Context $context): null
    {
        $eventId = (int) $input['event_id'];
        $formId = (int) $input['form_id'];
        if (isset($input['replaces'])) {
            $earlier = $this->drafts->ofClaim((string) $input['replaces'], $context);
            if ($earlier !== null && $earlier->userId === null) {
                $this->drafts->delete($earlier, $context);
            }
        }
        $now = $context->now();
        $draft = $this->drafts->ofClaim((string) $input['claim_hash'], $context);
        if ($draft === null || !$draft->isGuestOf($formId)) {
            $this->drafts->upsert(new Draft(
                $eventId,
                $formId,
                (int) $input['form_version'],
                null,
                (string) $input['claim_hash'],
                $this->answers,
                (int) $input['page'],
                $now,
                $now,
                claims: (int) $input['claims'],
            ), $context);

            return null;
        }
        if ($draft->claims !== (int) $input['claims']) {
            // A page from before a choice that changed the guest's draft (N1106): its answers are not kept.
            $error = new VersionConflict();
            throw $error;
        }
        $draft->formVersion = (int) $input['form_version'];
        $draft->answers = $this->answers;
        $draft->page = (int) $input['page'];
        $draft->updatedAt = $now;
        $this->drafts->upsert($draft, $context);

        return null;
    }
}
