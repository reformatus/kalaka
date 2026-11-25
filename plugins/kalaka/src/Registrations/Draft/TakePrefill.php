<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Draft;

use Kalaka\Forms\Api\AnswerSet;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\PublicUseCase;

/**
 * The one read of a one-shot prefill (`StashPrefill`, N676): the row under the token's hash is deleted
 * whether it has expired or not, and its answers are given only while it has not and it is the form's. A
 * row of another form is left for its own page (it expires). The periodic run sweeps what is never read
 * (`SweepDrafts`).
 */
final class TakePrefill implements PublicUseCase
{
    public function __construct(private readonly DraftRepository $drafts = new DraftRepository())
    {
    }

    public static function name(): string
    {
        return 'kalaka-portal/take-prefill';
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

    /** @return ?AnswerSet the answers; null when there are none, they expired or are another form's */
    public function execute(array $input, Context $context): ?AnswerSet
    {
        $row = $this->drafts->ofPrefill((string) $input['claim_hash'], $context);
        if ($row === null || $row->formId !== (int) $input['form_id']) {
            return null;
        }
        $this->drafts->delete($row, $context);

        return $row->expiresAt !== null && $row->expiresAt > $context->now() ? $row->answers : null;
    }
}
