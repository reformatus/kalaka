<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Draft;

use Kalaka\Forms\Api\AnswerSet;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\PublicUseCase;

/**
 * A handoff's answers kept for one page load, where the event's account mode has no drafts (S74, N9, N676):
 * a one-shot row of `kalaka_drafts` (`Draft::KIND_PREFILL`), keyed by the hash of the cookie token (the raw
 * token is only in the cookie), expiring after `Draft::PREFILL_SECONDS`. It is no draft: see `TakePrefill`.
 * The answers come through the constructor, already the ones the form's types accept; never input, so they
 * never reach a failed run's audit entry.
 */
final class StashPrefill implements PublicUseCase
{
    public function __construct(
        private readonly AnswerSet $answers,
        private readonly DraftRepository $drafts = new DraftRepository(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka-portal/stash-prefill';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'form_id' => ['type' => 'integer', 'minimum' => 1],
                'form_version' => ['type' => 'integer', 'minimum' => 1],
                'claim_hash' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
            ],
            'required' => ['event_id', 'form_id', 'form_version', 'claim_hash'],
            'additionalProperties' => false,
        ];
    }

    public function registrations(array $input): array
    {
        return [];
    }

    /** @return \DateTimeImmutable when the row expires: the one instant the cookie's expiry follows too */
    public function execute(array $input, Context $context): \DateTimeImmutable
    {
        $now = $context->now();
        $expires = $now->modify('+' . Draft::PREFILL_SECONDS . ' seconds');
        $this->drafts->upsert(new Draft(
            (int) $input['event_id'],
            (int) $input['form_id'],
            (int) $input['form_version'],
            null,
            (string) $input['claim_hash'],
            $this->answers,
            0,
            $now,
            $now,
            $expires,
            kind: Draft::KIND_PREFILL,
        ), $context);

        return $expires;
    }
}
