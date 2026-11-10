<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Mail\Delivery;
use Kalaka\Mail\DeliveryRepository;
use Kalaka\Mail\DeliveryStatus;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Where a use case that decides a mail is due queues it: a pending delivery whose key (event, mail,
 * registration, cause) makes the mail once-only. After the commit a sender run is nudged. mail.md,
 * "Five kinds of mail" and "Sending".
 * // [UNAPPROVED S8-11] deliveries are keyed by a registration; address deliveries come with the portal.
 */
final class MailQueue
{
    /** The nudge: a one-off sender run, scheduled after the commit that queued a delivery. */
    public const SEND_HOOK = 'kalaka_send_mail';

    private const MAIL_PATTERN = '/^[a-z][a-z0-9_]*$/';

    public function __construct(private readonly DeliveryRepository $deliveries = new DeliveryRepository())
    {
    }

    /**
     * Inserts the delivery unless its key is already queued, inside the calling use case's transaction.
     * Looks at neither the event's state nor the pause.
     *
     * @param string $mail the mail's key: lowercase letters, digits and underscores, at most 64
     * @param Subject $cause the record that caused the mail; its event is null or the registration's
     * @return bool whether a delivery was inserted; false when the key was already queued
     * @throws SystemFailure on a mail key or cause that is a programming error
     */
    public function add(
        Context $context,
        RegistrationKey $registration,
        string $mail,
        MailKind $kind,
        Subject $cause,
    ): bool {
        if (preg_match(self::MAIL_PATTERN, $mail) !== 1 || strlen($mail) > 64) {
            $reason = new \InvalidArgumentException('A mail key is [a-z][a-z0-9_]*, at most 64.');
            $error = new SystemFailure(null, $reason);
            throw $error;
        }
        if ($cause->eventId !== null && $cause->eventId !== $registration->eventId) {
            $reason = new \LogicException('The cause of a delivery belongs to another event.');
            $error = new SystemFailure(null, $reason);
            throw $error;
        }

        $delivery = new Delivery(
            $registration,
            $mail,
            $cause->type,
            $cause->key,
            $kind,
            DeliveryStatus::Pending,
            $context->now()
        );
        $stored = $this->deliveries->queue($delivery, $context);
        if ($stored === null) {
            return false;
        }

        $at = $context->now();
        $context->afterCommit(static function () use ($at): void {
            self::nudge($at);
        });

        return true;
    }

    /**
     * Schedules a one-off sender run. An identical run already scheduled counts as success.
     *
     * @throws \RuntimeException when WP-Cron refuses; a nudge's failure is only logged by its caller
     */
    public static function nudge(\DateTimeImmutable $at): void
    {
        $result = wp_schedule_single_event($at->getTimestamp(), self::SEND_HOOK, [], true);
        if ($result instanceof \WP_Error && $result->get_error_code() !== 'duplicate_event') {
            $error = new \RuntimeException(
                'Scheduling the mail sender failed: ' . $result->get_error_code() . ': ' . $result->get_error_message()
            );
            throw $error;
        }
    }
}
