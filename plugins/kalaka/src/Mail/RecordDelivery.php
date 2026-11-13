<?php

declare(strict_types=1);

namespace Kalaka\Mail;

use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Mail\Pause\MailStateRepository;
use Kalaka\Mail\Pause\PauseCause;

/**
 * The outcome of a claimed delivery's attempt. Without an error it is sent, and the errors in a row go
 * back to zero. With one it goes back to pending, keeping its error, and the errors in a row go
 * up; at two, sending pauses, naming this delivery and its error, with the system as the actor. Run by
 * the sender as the system, only for a delivery it claimed. mail.md, "Sending", "Failures" and "Pausing".
 */
final class RecordDelivery implements AdminUseCase
{
    /** mail.md, "Failures": two errors in a row pause sending. */
    public const ERRORS_TO_PAUSE = 2;

    public function __construct(
        private readonly DeliveryRepository $deliveries = new DeliveryRepository(),
        private readonly MailStateRepository $states = new MailStateRepository(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/record-delivery';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'id' => ['type' => 'integer', 'minimum' => 1],
                'mail_version' => ['type' => ['string', 'null'], 'maxLength' => 64],
                'error' => ['type' => ['string', 'null'], 'minLength' => 1],
            ],
            'required' => ['event_id', 'id', 'mail_version', 'error'],
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
     * @return bool whether this call paused sending
     * @throws NotFound when the delivery is gone
     * @throws SystemFailure when the delivery is not claimed
     */
    public function execute(array $input, Context $context): bool
    {
        $delivery = $this->deliveries->findForUpdate((int) $input['id'], $context);
        if ($delivery === null || $delivery->registration->eventId !== (int) $input['event_id']) {
            throw new NotFound();
        }
        $context->touch(DeliveryRepository::subjectOf($delivery));
        if ($delivery->status !== DeliveryStatus::Sending) {
            $cause = new \LogicException('Only a claimed delivery is recorded.');
            $error = new SystemFailure(null, $cause);
            throw $error;
        }

        $error = is_string($input['error']) ? $input['error'] : null;
        $delivery->mailVersion = is_string($input['mail_version']) ? $input['mail_version'] : null;
        $state = $this->states->forUpdate($context);
        if ($error === null) {
            $delivery->status = DeliveryStatus::Sent;
            $delivery->sentAt = $context->now();
            $this->deliveries->upsert($delivery, $context);
            if ($state->errorsInRow !== 0) {
                $state->errorsInRow = 0;
                $this->states->upsert($state, $context);
            }

            return false;
        }

        $delivery->status = DeliveryStatus::Pending;
        $delivery->error = $error;
        $this->deliveries->upsert($delivery, $context);

        $state->errorsInRow++;
        $pauses = $state->errorsInRow >= self::ERRORS_TO_PAUSE && !$state->isPaused();
        if ($pauses) {
            $state->pause($context->now(), PauseCause::Errors, null);
            $state->deliveryId = $delivery->id;
            $state->deliveryEventId = $delivery->registration->eventId;
            $state->error = $error;
        }
        $this->states->upsert($state, $context);

        return $pauses;
    }
}
