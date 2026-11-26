<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Draft;

use Kalaka\Forms\Api\AnswerSet;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\PublicUseCase;

/**
 * A link's or the quiz's answers handed over to a registration form (S74, owner ruling 2026-11-14: the quiz
 * handoff is a guest draft): kept as a guest draft under the new claim token's hash, with no expiry of its
 * own (it lives until the form is sent or the registration has closed; owner ruling 2026-11-26). The earlier
 * guest draft of the same browser (`replaces`, the hash of the cookie it had) is deleted: one browser holds
 * one. Anyone may run it, a guest included. The answers come through the constructor, already the ones the
 * form's types accept; never input, so they never reach a failed run's audit entry, and the draft's audit
 * records only that they were set.
 */
final class HandOverPrefill implements PublicUseCase
{
    public function __construct(
        private readonly AnswerSet $answers,
        private readonly DraftRepository $drafts = new DraftRepository(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka-portal/hand-over-prefill';
    }

    public function inputSchema(): array
    {
        $hash = ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'];

        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'form_id' => ['type' => 'integer', 'minimum' => 1],
                'form_version' => ['type' => 'integer', 'minimum' => 1],
                'claim_hash' => $hash,
                'replaces' => $hash,
            ],
            'required' => ['event_id', 'form_id', 'form_version', 'claim_hash'],
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
        $answers = $this->answers;
        $page = 0;
        if (isset($input['replaces'])) {
            $earlier = $this->drafts->ofClaim((string) $input['replaces'], $context);
            if ($earlier !== null && $earlier->userId === null) {
                if ($earlier->isGuestOf($formId)) {
                    // [UNAPPROVED N1106] the link wins on its keys; typing on other keys is kept.
                    $answers = $earlier->answers->overwrittenBy($this->answers);
                    $page = $earlier->page;
                }
                $this->drafts->delete($earlier, $context);
            }
        }
        $now = $context->now();
        $this->drafts->upsert(new Draft(
            (int) $input['event_id'],
            $formId,
            (int) $input['form_version'],
            null,
            (string) $input['claim_hash'],
            $answers,
            $page,
            $now,
            $now,
        ), $context);

        return null;
    }
}
