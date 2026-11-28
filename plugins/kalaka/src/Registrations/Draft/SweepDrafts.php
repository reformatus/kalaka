<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Draft;

use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Deletes drafts that are done (S74, owner ruling 2026-11-14: drafts are deleted when registration closes):
 * every draft of the event named. Run by the periodic run as the system (`DraftSweep`), which decides which
 * events are done; a draft has no time of its own to expire (owner ruling 2026-11-26).
 */
final class SweepDrafts implements AdminUseCase
{
    public function __construct(private readonly DraftRepository $drafts = new DraftRepository())
    {
    }

    public static function name(): string
    {
        return 'kalaka/sweep-drafts';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['event_id' => ['type' => 'integer', 'minimum' => 1]],
            'required' => ['event_id'],
            'additionalProperties' => false,
        ];
    }

    public function action(): ?string
    {
        return null;
    }

    public function event(array $input): ?int
    {
        return null;
    }

    public function targets(array $input): array
    {
        return [];
    }

    /** @return int how many drafts it deleted */
    public function execute(array $input, Context $context): int
    {
        $done = $this->drafts->ofEvent((int) $input['event_id'], $context);
        foreach ($done as $draft) {
            $this->drafts->delete($draft, $context);
        }

        return count($done);
    }
}
