<?php

declare(strict_types=1);

namespace Kalaka\Mail;

use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Pending to failed without an attempt: the delivery has no valid address, which retrying cannot fix.
 * It is no error for pausing and does not count against the budget. Run by the sender as the system.
 * mail.md, "Sending".
 * // [UNAPPROVED S8-10] site-level (`event()` is null), so only the system, WP-CLI and full access pass.
 */
final class FailDelivery implements AdminUseCase
{
    public function __construct(private readonly DeliveryRepository $deliveries = new DeliveryRepository())
    {
    }

    public static function name(): string
    {
        return 'kalaka/fail-delivery';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'id' => ['type' => 'integer', 'minimum' => 1],
                'error' => ['type' => 'string', 'minLength' => 1],
            ],
            'required' => ['event_id', 'id', 'error'],
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

    /**
     * @return bool whether this call marked it failed; false when it was no longer pending
     * @throws NotFound when the delivery is gone
     */
    public function execute(array $input, Context $context): bool
    {
        $delivery = $this->deliveries->findForUpdate((int) $input['id'], $context);
        if ($delivery === null || $delivery->registration->eventId !== (int) $input['event_id']) {
            throw new NotFound();
        }
        $context->touch(DeliveryRepository::subjectOf($delivery));
        if ($delivery->status !== DeliveryStatus::Pending) {
            return false;
        }

        $delivery->status = DeliveryStatus::Failed;
        $delivery->error = (string) $input['error'];
        $this->deliveries->upsert($delivery, $context);

        return true;
    }
}
