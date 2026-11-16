<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\Forms;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\PortalUseCase;
use Kalaka\Registrations\Api\DraftClaimOutcome;
use Kalaka\Registrations\Draft\DraftRepository;

/**
 * A logged-in person claims the guest draft their cookie names (S74): after signing up or logging in on
 * the way from a link or the quiz, or opening the form logged in. With no draft of their own for the form,
 * the guest draft becomes theirs. With one, the link's answers wait for the person's choice
 * (`ResolveDraftClaim`) only when they set a value the draft does not hold (`Forms::changedKeys()`, against
 * the form's latest definition, read before the run); when they change nothing, it is settled as keep_draft
 * at once: the guest draft is deleted, and there is nothing to choose. A guest draft that is gone, expired or
 * for another form claims nothing.
 *
 * When the guest draft becomes theirs, the draft's `claims` count goes up, so an autosave sent from a page
 * opened before (`SaveDraft`) cannot write over it.
 */
final class ClaimDraft implements PortalUseCase
{
    /** @param ?FormDefinition $definition the form's latest definition; null when the form is gone */
    public function __construct(
        private readonly ?FormDefinition $definition,
        private readonly Forms $forms,
        private readonly DraftRepository $drafts = new DraftRepository(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka-portal/claim-draft';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'form_id' => ['type' => 'integer', 'minimum' => 1],
                'claim_hash' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
            ],
            'required' => ['form_id', 'claim_hash'],
            'additionalProperties' => false,
        ];
    }

    public function registrations(array $input): array
    {
        return [];
    }

    public function execute(array $input, Context $context): DraftClaimOutcome
    {
        $actor = $context->actor();
        if (!$actor instanceof AccountActor) {
            $error = new PermissionDenied();
            throw $error;
        }
        $guest = $this->drafts->ofClaim((string) $input['claim_hash'], $context);
        if (
            $guest === null
            || $guest->userId !== null
            || $guest->expiresAt === null
            || $guest->expiresAt <= $context->now()
        ) {
            return DraftClaimOutcome::Gone;
        }
        if ($guest->formId !== (int) $input['form_id']) {
            return DraftClaimOutcome::OtherForm;
        }
        $own = $this->drafts->ofUser($guest->eventId, $guest->formId, $actor->userId, $context);
        if ($own !== null) {
            $changed = $this->definition === null
                || $this->forms->changedKeys($this->definition, $own->answers, $guest->answers) !== [];
            if ($changed) {
                return DraftClaimOutcome::Conflict;
            }
            $this->drafts->delete($guest, $context);

            return DraftClaimOutcome::Settled;
        }
        $guest->userId = $actor->userId;
        $guest->claimHash = null;
        $guest->expiresAt = null;
        $guest->updatedAt = $context->now();
        ++$guest->claims;
        $this->drafts->upsert($guest, $context);

        return DraftClaimOutcome::Claimed;
    }
}
