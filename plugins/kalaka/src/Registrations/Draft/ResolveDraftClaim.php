<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Draft;

use Kalaka\Forms\Api\DraftClaimChoice;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\PortalUseCase;
use Kalaka\Registrations\Api\DraftClaimRefused;

/**
 * The person's choice when a link's or the quiz's answers (the guest draft their cookie names) meet their
 * own draft of the form (S74, owner ruling 2026-11-14): merge, the link's answers written over the draft's
 * and the rest kept; keep the draft and drop the link; or keep the link and drop the draft. The guest draft
 * is deleted whatever the choice. With no draft of the person's own, the guest draft simply becomes theirs.
 *
 * When the claim changes what the person's draft holds (merge, keep_link, or the guest draft becoming
 * theirs), the draft's `claims` count goes up: an autosave sent from the page before the choice carries the
 * old count and is refused (`SaveDraft`), so it never brings back the answers the choice replaced. keep_draft
 * changes nothing, and the count stays.
 *
 * Safe to repeat, once per claim token (a lost answer, a second click): the person's draft records the
 * claim it settled and the choice (`resolvedClaim`, `resolvedChoice`). Once the guest draft is gone, the same
 * choice again changes nothing and answers as the first did. Another choice, a guest draft that is gone
 * (sent, or swept once registration closed) or of another form, and a claim settled on the form's page with
 * no choice are refused (`DraftClaimRefused`), and nothing is changed. [UNAPPROVED N11]
 */
final class ResolveDraftClaim implements PortalUseCase
{
    public function __construct(private readonly DraftRepository $drafts = new DraftRepository())
    {
    }

    public static function name(): string
    {
        return 'kalaka-portal/resolve-draft-claim';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'form_id' => ['type' => 'integer', 'minimum' => 1],
                'claim_hash' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
                'choice' => ['type' => 'string', 'enum' => array_column(DraftClaimChoice::cases(), 'value')],
            ],
            'required' => ['event_id', 'form_id', 'claim_hash', 'choice'],
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
        if (!$actor instanceof AccountActor) {
            $error = new PermissionDenied();
            throw $error;
        }
        $hash = (string) $input['claim_hash'];
        $choice = DraftClaimChoice::from((string) $input['choice']);
        $guest = $this->drafts->ofClaim($hash, $context);
        if ($guest === null || !$guest->isGuestOf((int) $input['form_id'])) {
            // A repeat of the choice that settled this claim answers as the first did; anything else is refused.
            $own = $this->drafts->ofUser((int) $input['event_id'], (int) $input['form_id'], $actor->userId, $context);
            if ($own !== null && $own->resolvedClaim === $hash && $own->resolvedChoice === $choice->value) {
                return null;
            }
            $error = new DraftClaimRefused();
            throw $error;
        }
        $own = $this->drafts->ofUser($guest->eventId, $guest->formId, $actor->userId, $context);
        $now = $context->now();
        if ($own === null) {
            $guest->userId = $actor->userId;
            $guest->resolvedClaim = $hash;
            $guest->resolvedChoice = $choice->value;
            $guest->claimHash = null;
            $guest->updatedAt = $now;
            ++$guest->claims;
            $this->drafts->upsert($guest, $context);

            return null;
        }
        if ($choice !== DraftClaimChoice::KeepDraft) {
            $own->answers = $choice === DraftClaimChoice::Merge
                ? $own->answers->overwrittenBy($guest->answers)
                : $guest->answers;
            $own->formVersion = max($own->formVersion, $guest->formVersion);
            $own->updatedAt = $now;
            ++$own->claims;
        }
        $own->resolvedClaim = $hash;
        $own->resolvedChoice = $choice->value;
        $this->drafts->upsert($own, $context);
        $this->drafts->delete($guest, $context);

        return null;
    }
}
