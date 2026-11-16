<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Draft\DraftRepository;

/**
 * Deletes drafts that are done (S74, owner ruling 2026-11-14: drafts are deleted when registration closes):
 * every draft of the event named, or, with none named, every guest draft whose lifetime has passed. Run by
 * the periodic run as the system (`DraftSweep`), which decides which events are done.
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
        $done = isset($input['event_id'])
            ? $this->drafts->ofEvent((int) $input['event_id'], $context)
            : $this->drafts->expired($context->now(), $context);
        foreach ($done as $draft) {
            $this->drafts->delete($draft, $context);
        }

        return count($done);
    }
}
