<?php

declare(strict_types=1);

namespace Kalaka\Mail;

use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Pending to sending, before `wp_mail` is called: the claim that keeps two sender runs from sending one
 * delivery at the same time. It counts the attempt and its time, which the hourly budget counts. Run by
 * the sender as the system. mail.md, "Sending".
 */
final class ClaimDelivery implements AdminUseCase
{
    public function __construct(private readonly DeliveryRepository $deliveries = new DeliveryRepository())
    {
    }

    public static function name(): string
    {
        return 'kalaka/claim-delivery';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'id' => ['type' => 'integer', 'minimum' => 1],
            ],
            'required' => ['event_id', 'id'],
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
     * @return bool whether this call claimed the delivery; false when it was no longer pending
     * @throws NotFound when the delivery is gone (cleared from the queue)
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

        $delivery->status = DeliveryStatus::Sending;
        $delivery->attempts++;
        $delivery->claimedAt = $context->now();
        $this->deliveries->upsert($delivery, $context);

        return true;
    }
}
