<?php

declare(strict_types=1);

namespace Kalaka\Mail\Cli;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Entry\CommandFailure;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Mail\Api\Deliveries;
use Kalaka\Mail\Api\MailStats;
use Kalaka\Mail\Api\TroubledDelivery;
use Kalaka\Mail\ClearEventMail;
use Kalaka\Mail\ClearMailQueue;
use Kalaka\Mail\DeliveryQuery;
use Kalaka\Mail\EventMailNotHeld;
use Kalaka\Mail\Pause\MailStateQuery;
use Kalaka\Mail\Pause\PauseMail;
use Kalaka\Mail\Pause\ResumeMail;
use Kalaka\Mail\Pause\ResumeNeedsPerson;
use Kalaka\Mail\Pause\SetMailBudget;

/**
 * `wp kalaka mail status|pause|resume|budget|clear-queue|clear-event|failed`: the mail engine from the command
 * line (mail.md, "Pausing", "Failures"), through the use cases `PauseMail`, `ResumeMail`, `SetMailBudget`,
 * `ClearMailQueue` and `ClearEventMail`, never through SQL. It runs as the command line without a user (full
 * access), or, with WP-CLI's `--user`, checked as that user; `status` and `failed` read for full access only.
 * Only a person resumes sending: `ResumeMail` refuses an actor that is no person, and `resume` adds the hint to
 * give `--user`. The screen for the same is S41's.
 */
final class MailCommand
{
    private const EVENT_FIELDS = ['event', 'queued', 'sent', 'failed', 'with_error', 'stuck_sending'];

    private const FAILED_FIELDS = ['code', 'mail', 'kind', 'status', 'attempts', 'attempted_at_utc', 'error'];

    /**
     * @param ?\Closure(string, list<array<string, mixed>>, list<string>): void $print prints rows in a format
     * @param ?\Closure(string, array<string, mixed>): void $confirm asks to go on; ends the command when declined
     */
    public function __construct(
        private readonly MailStats $stats = new MailStats(),
        private readonly MailStateQuery $state = new MailStateQuery(),
        private readonly DeliveryQuery $deliveries = new DeliveryQuery(),
        private readonly Events $events = new Events(),
        private readonly Deliveries $troubled = new Deliveries(),
        private readonly ?\Closure $print = null,
        private readonly ?\Closure $confirm = null,
    ) {
    }

    public static function register(): void
    {
        \WP_CLI::add_command('kalaka mail', new self(), [
            'shortdesc' => 'Shows, pauses and resumes the mail engine, clears its queue and lists failed deliveries.',
        ]);
    }

    /**
     * Shows whether sending runs or is paused (cause, who, since when, and for a pause after errors the
     * delivery and its error), the errors in a row, the pending deliveries of the site and each event's
     * counts. `--format` applies to the per-event table.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, json or csv.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function status(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($assocArgs): void {
            $format = CliInput::format($assocArgs);
            CliInput::fullAccess();
            $state = $this->state->current();
            $queued = $this->stats->site()->queued;

            if ($state->pausedAt === null) {
                \WP_CLI::line('Sending: running');
            } else {
                \WP_CLI::line('Sending: paused');
                \WP_CLI::line('Cause: ' . ($state->cause === null ? 'unknown' : $state->cause->value));
                \WP_CLI::line('Paused by: ' . self::who($state->pausedBy));
                \WP_CLI::line('Paused since: ' . self::utc($state->pausedAt));
                if ($state->deliveryId !== null) {
                    $event = $state->deliveryEventId ?? 0;
                    \WP_CLI::line('Delivery: ' . $state->deliveryId . ' (event ' . $event . ')');
                }
                if ($state->error !== null) {
                    \WP_CLI::line('Error: ' . $state->error);
                }
            }
            \WP_CLI::line('Errors in a row: ' . $state->errorsInRow);
            \WP_CLI::line('Pending deliveries: ' . $queued);

            $rows = [];
            foreach ($this->stats->ofEvents($this->deliveries->eventIds()) as $eventId => $count) {
                $rows[] = [
                    'event' => $eventId,
                    'queued' => $count->queued,
                    'sent' => $count->sent,
                    'failed' => $count->failed,
                    'with_error' => $count->withError,
                    'stuck_sending' => $count->stuckSending,
                ];
            }
            $this->show($format, $rows, self::EVENT_FIELDS);
        });
    }

    /**
     * Pauses sending by hand; deliveries pile up until a person resumes. Nothing changes when sending is
     * already paused, for either cause.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function pause(array $args, array $assocArgs): void
    {
        Boundary::cli(function (): void {
            $paused = Runner::current()->run(new PauseMail(), [], CliActor::current());
            \WP_CLI::success($paused === true ? 'Sending is paused.' : 'Sending was already paused; nothing changed.');
        });
    }

    /**
     * Resumes sending, whatever paused it. Only a person resumes: give the user with `--user=<user>`; without
     * one the command is refused. The errors in a row stay, so a delivery that fails again pauses sending at once.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function resume(array $args, array $assocArgs): void
    {
        Boundary::cli(function (): void {
            try {
                $resumed = Runner::current()->run(new ResumeMail(), [], CliActor::current());
            } catch (ResumeNeedsPerson) {
                // The use case holds the rule; the command only adds the hint for the command line.
                throw new CommandFailure('Only a person resumes sending: give the user with --user=<user>.');
            }
            \WP_CLI::success($resumed === true ? 'Sending is resumed.' : 'Sending was not paused; nothing changed.');
        });
    }

    /**
     * Sets the hourly budget: how many mails the sender may send in an hour, from 1 to 2000. The next run
     * uses it. Nothing changes when it already is that value.
     *
     * ## OPTIONS
     *
     * <n>
     * : The hourly budget, a whole number from 1 to 2000.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function budget(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args): void {
            // The raw argument goes to the use case, whose one typed refusal answers every wrong value.
            $given = $args[0] ?? '';
            $changed = Runner::current()->run(new SetMailBudget(), ['hourly_budget' => $given], CliActor::current());
            $budget = (int) $given;
            \WP_CLI::success($changed === true
                ? 'The hourly budget is ' . $budget . '.'
                : 'The hourly budget already was ' . $budget . '; nothing changed.');
        });
    }

    /**
     * Deletes every pending delivery of the site, those that carry an error included. Sending, sent and
     * failed deliveries stay. Asks first.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Answer yes to the confirmation.
     *
     * @subcommand clear-queue
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function clearQueue(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($assocArgs): void {
            $actor = CliActor::current();
            AdminAccess::check(new ClearMailQueue(), [], $actor);
            $this->ask('Delete all ' . $this->stats->site()->queued . ' pending deliveries of the site?', $assocArgs);
            $deleted = Runner::current()->run(new ClearMailQueue(), [], $actor);
            \WP_CLI::success('Deleted ' . self::count($deleted) . ' pending deliveries.');
        });
    }

    /**
     * Deletes the pending deliveries of one event that is not published (its held mail). Refused while the
     * event is published. Asks first.
     *
     * ## OPTIONS
     *
     * <event_id>
     * : The event ID.
     *
     * [--yes]
     * : Answer yes to the confirmation.
     *
     * @subcommand clear-event
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function clearEvent(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args, $assocArgs): void {
            $eventId = CliInput::eventId($args);
            $actor = CliActor::current();
            $input = ['event_id' => $eventId];
            AdminAccess::check(new ClearEventMail(), $input, $actor);
            $event = $this->events->find($eventId) ?? throw new NotFound();
            try {
                ClearEventMail::refuse($event, $this->events);
            } catch (EventMailNotHeld) {
                // The use case's own check, run ahead so the refusal comes before the question.
                $error = new CommandFailure(
                    'Event ' . $eventId . ' is published: its pending mail is on its way, not held.'
                );
                throw $error;
            }
            $queued = $this->stats->ofEvents([$eventId])[$eventId]->queued ?? 0;
            $this->ask('Delete the ' . $queued . ' pending deliveries of event ' . $eventId . '?', $assocArgs);
            $deleted = Runner::current()->run(new ClearEventMail(), $input, $actor);
            \WP_CLI::success('Deleted ' . self::count($deleted) . ' pending deliveries of event ' . $eventId . '.');
        });
    }

    /**
     * Lists an event's deliveries that need a look: failed (no valid address), pending with the error of an
     * earlier attempt, and left in "sending". It shows the registration code, not the recipient's address;
     * look the registration up by its code. The error is printed as stored.
     *
     * ## OPTIONS
     *
     * <event_id>
     * : The event ID.
     *
     * [--format=<format>]
     * : table, json or csv.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function failed(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args, $assocArgs): void {
            CliInput::fullAccess();
            $eventId = CliInput::existingEventId($args);
            $format = CliInput::format($assocArgs);
            $rows = array_map(static fn (TroubledDelivery $delivery): array => [
                'code' => $delivery->code,
                'mail' => $delivery->mail,
                'kind' => $delivery->kind->value,
                'status' => $delivery->status,
                'attempts' => $delivery->attempts,
                'attempted_at_utc' => $delivery->lastAttemptAt === null ? '' : self::utc($delivery->lastAttemptAt),
                'error' => $delivery->error ?? '',
            ], $this->troubled->troubled($eventId));
            $this->show($format, $rows, self::FAILED_FIELDS);
        });
    }

    /** @param array<string, mixed> $assocArgs */
    private function ask(string $question, array $assocArgs): void
    {
        if ($this->confirm !== null) {
            ($this->confirm)($question, $assocArgs);

            return;
        }
        \WP_CLI::confirm($question, $assocArgs);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $fields
     */
    private function show(string $format, array $rows, array $fields): void
    {
        if ($this->print !== null) {
            ($this->print)($format, $rows, $fields);

            return;
        }
        \WP_CLI\Utils\format_items($format, $rows, $fields);
    }

    private static function who(?int $userId): string
    {
        if ($userId === null) {
            return 'the system or the command line';
        }
        $user = get_userdata($userId);

        return $user === false ? 'user ' . $userId : $user->user_login . ' (user ' . $userId . ')';
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') . ' UTC';
    }

    private static function count(mixed $deleted): int
    {
        return is_int($deleted) ? $deleted : 0;
    }
}
