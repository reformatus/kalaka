<?php

declare(strict_types=1);

namespace Kalaka\Mail;

use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Audit\Correlation;
use Kalaka\Foundation\Api\Database\NamedLock;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\Periodic\PeriodicStep;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Mail\Api\MailQueue;
use Kalaka\Mail\Pause\MailStateQuery;

/**
 * The one sender. It sends the pending deliveries of published events within the hour's budget and its
 * run's share, retried delivery first. Before each claim it reads the pause fresh and checks the address;
 * a delivery is claimed in a committed use case before `wp_mail` is called. On an error the delivery goes
 * back to pending and the run stops; the second error in a row pauses sending. It runs from the nudge,
 * from itself and as a step of the periodic run, never in an interactive request, and only one run at a
 * time. mail.md, "Sending", "Failures" and "Pausing".
 */
final class Sender implements PeriodicStep
{
    /** The named lock that keeps two sender runs from overlapping. */
    public const LOCK = 'mail_sender';

    /**
     * @param \Closure(): Runner $runner resolved when a run starts
     * @param ?MailComposer $composer none: the sender does not run at all
     */
    public function __construct(
        private readonly \Closure $runner,
        private readonly ?MailComposer $composer,
        private readonly Stopwatch $stopwatch = new Stopwatch(),
        private readonly DeliveryQuery $query = new DeliveryQuery(),
        private readonly MailStateQuery $state = new MailStateQuery(),
        private readonly Events $events = new Events(),
        private readonly NamedLock $lock = new NamedLock(self::LOCK),
    ) {
    }

    public function name(): string
    {
        return 'mail_sender';
    }

    public function run(): void
    {
        $this->send();
    }

    /**
     * @return ?SendReport null when the run did not happen: the site is closed, no composer is bound yet,
     *                     or another sender run holds the lock
     * @throws \Throwable whatever a claim, record or failure use case throws (a vanished delivery aside):
     *         the run ends there and the caller's boundary logs it; and a failed self-nudge
     */
    public function send(): ?SendReport
    {
        // Without a composer the sender claims nothing: deliveries stay pending.
        if ($this->composer === null || !LoadState::current()->isOpen()) {
            return null;
        }
        // One sender run at a time; a run that cannot take the lock ends at once.
        if (!$this->lock->acquire()) {
            return null;
        }

        $nudgeAt = null;
        try {
            [$report, $nudgeAt] = $this->deliver($this->composer, ($this->runner)());
        } finally {
            $this->lock->release();
        }

        if ($nudgeAt !== null) {
            MailQueue::nudge($nudgeAt);
        }

        return $report;
    }

    /** @return array{SendReport, ?\DateTimeImmutable} what the run did, and when to run again, if anything is left */
    private function deliver(MailComposer $composer, Runner $runner): array
    {
        $this->stopwatch->start();
        $now = $runner->clock()->now();
        $state = $this->state->current();
        if ($state->isPaused()) {
            return [new SendReport(), null];
        }
        $share = (new Throttle($this->query))->share($now, $state->hourlyBudget);
        $published = array_map(static fn (Event $event): int => $event->id, $this->events->published());
        // One more than the share tells whether candidates are left.
        $limit = max($share, 0) + 1;
        $candidates = $this->query->candidates($published, $limit);

        $sent = 0;
        $invalid = 0;
        $claimed = 0;
        foreach ($candidates as $delivery) {
            if ($claimed >= $share) {
                return [new SendReport($sent, 0, $invalid), Throttle::nextSlotStart($now)];
            }
            if ($this->stopwatch->capReached()) {
                return [new SendReport($sent, 0, $invalid), $runner->clock()->now()];
            }
            if ($this->state->current()->isPaused()) {
                return [new SendReport($sent, 0, $invalid), null];
            }

            $to = $composer->recipient($delivery);
            if ($to === null || !is_email($to)) {
                if ($this->fail($runner, $delivery)) {
                    $invalid++;
                }
                continue;
            }
            if (!$this->claim($runner, $delivery)) {
                continue;
            }
            $claimed++;

            [$version, $error] = $this->attempt($composer, $delivery, $to);
            $paused = $runner->run(
                new RecordDelivery(),
                $this->identity($delivery) + ['mail_version' => $version, 'error' => $error?->text()],
                new SystemActor()
            ) === true;
            if ($error !== null) {
                // mail.md, "Failures": on an error the run stops; the next run tries this delivery first.
                return [new SendReport($sent, 1, $invalid, $paused), null];
            }
            $sent++;
        }

        // Every candidate was handled, but invalid addresses used no share: more may be waiting.
        $left = count($candidates) === $limit && $limit > 1;

        return [new SendReport($sent, 0, $invalid), $left ? $runner->clock()->now() : null];
    }

    /** @return bool whether this run claimed it; false when it is gone or no longer pending */
    private function claim(Runner $runner, Delivery $delivery): bool
    {
        try {
            return $runner->run(new ClaimDelivery(), $this->identity($delivery), new SystemActor()) === true;
        } catch (NotFound) {
            // A delivery cleared between the read and the claim is skipped.
            return false;
        }
    }

    /** @return bool whether this run marked it failed */
    private function fail(Runner $runner, Delivery $delivery): bool
    {
        $error = new DeliveryError(DeliveryError::ADDRESS, 'invalid_address', null, Correlation::id());
        try {
            return $runner->run(
                new FailDelivery(),
                $this->identity($delivery) + ['error' => $error->text()],
                new SystemActor()
            ) === true;
        } catch (NotFound) {
            return false;
        }
    }

    /**
     * Renders and sends one claimed delivery. The error is stored as reported, and the error log gets the
     * same failure under the same correlation ID.
     *
     * @return array{?string, ?DeliveryError} the rendered body's version, and the error, if one was reported
     */
    private function attempt(MailComposer $composer, Delivery $delivery, string $to): array
    {
        try {
            $message = $composer->compose($delivery, $to);
        } catch (\Throwable $e) {
            $this->report($delivery, $e);

            return [null, DeliveryError::thrown(DeliveryError::COMPOSE, $e, Correlation::id())];
        }

        $failure = null;
        $listener = static function (\WP_Error $error) use (&$failure): void {
            $failure ??= $error;
        };
        add_action('wp_mail_failed', $listener);
        try {
            $sent = wp_mail($to, $message->subject, $message->body, $message->headers);
        } catch (\Throwable $e) {
            $this->report($delivery, $e);

            return [$message->version, DeliveryError::thrown(DeliveryError::SEND, $e, Correlation::id())];
        } finally {
            remove_action('wp_mail_failed', $listener);
        }

        if ($failure instanceof \WP_Error) {
            $code = (string) $failure->get_error_code();
            $this->report($delivery, new \RuntimeException($code . ': ' . $failure->get_error_message()));

            return [
                $message->version,
                new DeliveryError(DeliveryError::SEND, $code, $failure->get_error_message(), Correlation::id()),
            ];
        }
        if (!$sent) {
            $this->report($delivery, new \RuntimeException('wp_mail() returned false.'));

            $error = new DeliveryError(DeliveryError::SEND, 'wp_mail_false', null, Correlation::id());

            return [$message->version, $error];
        }

        return [$message->version, null];
    }

    /** The full error, for the error log only. */
    private function report(Delivery $delivery, \Throwable $error): void
    {
        Boundary::report(
            'mail delivery ' . $delivery->id . ' of event ' . $delivery->registration->eventId,
            $error
        );
    }

    /** @return array{event_id: int, id: int} */
    private function identity(Delivery $delivery): array
    {
        return ['event_id' => $delivery->registration->eventId, 'id' => $delivery->id];
    }
}
