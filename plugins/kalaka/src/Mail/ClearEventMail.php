<?php

declare(strict_types=1);

namespace Kalaka\Mail;

use Kalaka\Events\Api\Events;
use Kalaka\Events\Api\Event;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Mail\Api\MailHold;

/**
 * Drops one event's held deliveries: the pending ones of an event that is not published, which the sender
 * skips (mail.md, "Pausing": the admin sees the pending count with the option to clear them). Refused while
 * the event is published, since its pending deliveries are then not held but on their way. Site-level, so
 * only full access (and WP-CLI) runs it; audited like the global clear.
 */
final class ClearEventMail implements AdminUseCase
{
    public function __construct(
        private readonly DeliveryRepository $deliveries = new DeliveryRepository(),
        private readonly Events $events = new Events(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/clear-event-mail';
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

    /**
     * The one check that the event's mail is held: the use case runs it, and the command line before it asks.
     *
     * @throws EventMailNotHeld while the event is published
     */
    public static function refuse(Event $event, ?Events $events = null): void
    {
        if (!MailHold::isHeld($event, $events)) {
            throw new EventMailNotHeld();
        }
    }

    /**
     * @return int the deliveries deleted
     * @throws NotFound when there is no such event
     * @throws EventMailNotHeld while the event is published
     */
    public function execute(array $input, Context $context): int
    {
        $eventId = (int) $input['event_id'];
        $event = $this->events->find($eventId) ?? throw new NotFound();
        $context->touch(Subject::event($eventId));
        self::refuse($event, $this->events);

        $pending = $this->deliveries->pendingOfEventForUpdate($eventId, $context);
        foreach ($pending as $delivery) {
            $this->deliveries->delete($delivery, $context);
        }

        return count($pending);
    }
}
