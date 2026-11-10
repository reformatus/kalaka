<?php

declare(strict_types=1);

namespace Kalaka\Mail;

use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Clears the queue: deletes every pending delivery of the site, those that carry an error included.
 * A delivery in "sending" or a sent or failed one is kept. Site-level. mail.md, "Pausing".
 * // [UNAPPROVED N8] one use case, one transaction for the whole queue.
 */
final class ClearMailQueue implements AdminUseCase
{
    public function __construct(private readonly DeliveryRepository $deliveries = new DeliveryRepository())
    {
    }

    public static function name(): string
    {
        return 'kalaka/clear-mail-queue';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
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

    /** @return int the deliveries deleted */
    public function execute(array $input, Context $context): int
    {
        $pending = $this->deliveries->pendingForUpdate($context);
        foreach ($pending as $delivery) {
            $this->deliveries->delete($delivery, $context);
        }

        return count($pending);
    }
}
