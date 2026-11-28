<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Draft;

use Kalaka\Forms\Api\DraftClaimChoice;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\Forms;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\PublicUseCase;
use Kalaka\Registrations\Api\DraftClaimOutcome;

/**
 * The page load after a handoff (S74, N1106, owner ruling 2026-11-26): the link's or the quiz's answers, kept as
 * a guest draft that waits in the handoff cookie (`HandOverPrefill`), meet the guest draft the draft cookie
 * names, if any. Anyone may run it, a guest included; the handoff's draft is locked first, then the earlier one.
 *
 * - No earlier draft, or one of another form or with no answers (it is deleted): the waiting draft becomes the
 *   browser's (`Claimed`: the caller moves the token into the draft cookie). Nothing to choose.
 * - An earlier draft of the same form whose values the link sets all alike (`Forms::changedKeys()`, against the
 *   form's latest definition, read before the run): settled as keep_draft at once (`Settled`): the waiting
 *   draft is deleted, nothing to choose. The earlier draft records the claim, so a choice sent for it later
 *   is answered as `ResolveGuestHandoff` answers a repeat.
 * - An earlier draft of the same form that the link would change: it waits for the person's choice
 *   (`Conflict`), and nothing is written.
 *
 * A waiting draft that is gone is `Gone`; one of another form is `OtherForm`: its cookie stays for that form.
 */
final class SettleHandoff implements PublicUseCase
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
        return 'kalaka-portal/settle-handoff';
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
            ],
            'required' => ['form_id', 'handoff_hash'],
            'additionalProperties' => false,
        ];
    }

    public function registrations(array $input): array
    {
        return [];
    }

    public function execute(array $input, Context $context): DraftClaimOutcome
    {
        $formId = (int) $input['form_id'];
        $waiting = $this->drafts->ofClaim((string) $input['handoff_hash'], $context);
        if ($waiting === null || $waiting->userId !== null) {
            return DraftClaimOutcome::Gone;
        }
        if ($waiting->formId !== $formId) {
            return DraftClaimOutcome::OtherForm;
        }
        $earlier = isset($input['claim_hash']) ? $this->drafts->ofClaim((string) $input['claim_hash'], $context) : null;
        if ($earlier === null || $earlier->userId !== null || $earlier->claimHash === $waiting->claimHash) {
            return DraftClaimOutcome::Claimed;
        }
        if (!$earlier->isGuestOf($formId) || $earlier->answers->isEmpty()) {
            // One browser holds one draft: the earlier one (of another form, or never typed into) gives way.
            $this->drafts->delete($earlier, $context);

            return DraftClaimOutcome::Claimed;
        }
        $changed = $this->definition === null
            || $this->forms->changedKeys($this->definition, $earlier->answers, $waiting->answers) !== [];
        if ($changed) {
            return DraftClaimOutcome::Conflict;
        }
        $earlier->resolvedClaim = $waiting->claimHash;
        $earlier->resolvedChoice = DraftClaimChoice::KeepDraft->value;
        $this->drafts->upsert($earlier, $context);
        $this->drafts->delete($waiting, $context);

        return DraftClaimOutcome::Settled;
    }
}
