<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\PortalUseCase;
use Kalaka\Registrations\Api\DraftClaimOutcome;
use Kalaka\Registrations\Draft\DraftRepository;

/**
 * A logged-in person claims the guest draft their cookie names (S74): after signing up or logging in on
 * the way from a link or the quiz, or opening the form logged in. With no draft of their own for the form,
 * the guest draft becomes theirs. With one, nothing changes: the link's answers wait for the person's
 * choice (`ResolveDraftClaim`). A guest draft that is gone, expired or for another form claims nothing.
 */
final class ClaimDraft implements PortalUseCase
{
    public function __construct(private readonly DraftRepository $drafts = new DraftRepository())
    {
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
        if ($this->drafts->ofUser($guest->eventId, $guest->formId, $actor->userId, $context) !== null) {
            return DraftClaimOutcome::Conflict;
        }
        $guest->userId = $actor->userId;
        $guest->claimHash = null;
        $guest->expiresAt = null;
        $guest->updatedAt = $context->now();
        $this->drafts->upsert($guest, $context);

        return DraftClaimOutcome::Claimed;
    }
}
