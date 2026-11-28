<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Draft;

use Kalaka\Forms\Api\DraftClaimChoice;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\PublicUseCase;
use Kalaka\Registrations\Api\DraftClaimRefused;

/**
 * A guest's choice when a link's or the quiz's answers (the guest draft that waits in the handoff cookie) meet
 * the guest draft their draft cookie names (S74, N1106, owner ruling 2026-11-26): the same three choices as
 * `ResolveDraftClaim`, settled on the guest's own draft, which keeps its token and its cookie. merge writes the
 * link's answers over the draft's and keeps the rest; keep_draft drops the link; keep_link replaces the draft's
 * answers with the link's. The waiting draft is deleted whatever the choice. A guest has no account, so the
 * request proves itself with the two tokens: the draft's own and the handoff's, both held in the cookies.
 *
 * A choice that changes the answers raises the draft's `claims` count, so a save from the page before the
 * choice is refused (`SaveDraft`). Safe to repeat, once per handoff: the guest's draft records the handoff it
 * settled and the choice (`resolvedClaim`, `resolvedChoice`), and once the waiting draft is gone the same
 * choice again changes nothing. Another choice, a waiting draft that is gone (sent, swept) or of another form,
 * and an earlier draft that is gone or of another form are refused (`DraftClaimRefused`), and nothing is
 * changed. The handoff's draft is locked first, then the guest's own.
 */
final class ResolveGuestHandoff implements PublicUseCase
{
    public function __construct(private readonly DraftRepository $drafts = new DraftRepository())
    {
    }

    public static function name(): string
    {
        return 'kalaka-portal/resolve-guest-handoff';
    }

    public function inputSchema(): array
    {
        $hash = ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'];

        return [
            'type' => 'object',
            'properties' => [
                'form_id' => ['type' => 'integer', 'minimum' => 1],
                'handoff_hash' => $hash,
                'claim_hash' => $hash,
                'choice' => ['type' => 'string', 'enum' => array_column(DraftClaimChoice::cases(), 'value')],
            ],
            'required' => ['form_id', 'handoff_hash', 'claim_hash', 'choice'],
            'additionalProperties' => false,
        ];
    }

    public function registrations(array $input): array
    {
        return [];
    }

    public function execute(array $input, Context $context): null
    {
        $formId = (int) $input['form_id'];
        $handoff = (string) $input['handoff_hash'];
        $choice = DraftClaimChoice::from((string) $input['choice']);
        $waiting = $this->drafts->ofClaim($handoff, $context);
        $kept = $this->drafts->ofClaim((string) $input['claim_hash'], $context);
        if ($kept === null || !$kept->isGuestOf($formId)) {
            $error = new DraftClaimRefused();
            throw $error;
        }
        if ($waiting === null || !$waiting->isGuestOf($formId)) {
            // A repeat of the choice that settled this handoff answers as the first did; anything else is refused.
            if ($kept->resolvedClaim === $handoff && $kept->resolvedChoice === $choice->value) {
                return null;
            }
            $error = new DraftClaimRefused();
            throw $error;
        }
        DraftChoice::apply($kept, $waiting, $choice, $handoff, $context->now());
        $this->drafts->upsert($kept, $context);
        $this->drafts->delete($waiting, $context);

        return null;
    }
}
