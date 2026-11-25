<?php

declare(strict_types=1);

namespace Kalaka\Mail\Cli;

use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Entry\CommandFailure;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Mail\Api\BatchInfo;
use Kalaka\Mail\Api\ManualMails;

/**
 * `wp kalaka mail batches`: lists what `send` queued, from the batches' own records and never from the
 * deliveries (mail.md, "Words"). For full access only.
 */
final class ManualMailBatchesCommand
{
    private const BATCH_FIELDS = ['batch', 'created_at_utc', 'by', 'body', 'layout', 'queued', 'skipped'];

    private const MEMBER_FIELDS = ['code', 'outcome'];

    /** @param ?\Closure(string, list<array<string, mixed>>, list<string>): void $print prints rows in a format */
    public function __construct(
        private readonly ManualMails $manualMails = new ManualMails(),
        private readonly ?\Closure $print = null,
    ) {
    }

    public static function register(): void
    {
        \WP_CLI::add_command('kalaka mail batches', new self());
    }

    /**
     * Lists the manual mail batches of an event, or one batch's registrations.
     *
     * Newest batch first; with a batch ID, the registrations of that batch and what became of
     * each. It reads the batch's own record, not the deliveries: `wp kalaka mail failed` shows
     * what a delivery did.
     *
     * ## OPTIONS
     *
     * <event_id>
     * : The event ID.
     *
     * [<batch_id>]
     * : One batch.
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
    public function __invoke(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args, $assocArgs): void {
            CliInput::fullAccess();
            $eventId = CliInput::existingEventId($args);
            $format = CliInput::format($assocArgs);
            if (isset($args[1])) {
                if (preg_match('/\A[1-9][0-9]*\z/', $args[1]) !== 1) {
                    throw new CommandFailure('The batch ID must be a positive whole number.');
                }
                $batch = $this->manualMails->batch($eventId, (int) $args[1]) ?? throw new NotFound();
                $rows = array_map(static fn (array $member): array => [
                    'code' => $member['code'],
                    'outcome' => $member['outcome'],
                ], $batch->members ?? []);
                $this->show($format, $rows, self::MEMBER_FIELDS);

                return;
            }
            $rows = array_map(fn (BatchInfo $batch): array => [
                'batch' => $batch->id,
                'created_at_utc' => $batch->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'by' => self::who($batch->createdBy),
                'body' => $batch->bodyId . ' ' . ($batch->subject ?? '(gone)'),
                'layout' => $batch->layout ? 'yes' : 'no',
                'queued' => $batch->queued,
                'skipped' => $batch->skipped,
            ], $this->manualMails->batches($eventId));
            $this->show($format, $rows, self::BATCH_FIELDS);
        });
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
        // WP-CLI's helper, not installed for the static analysis.
        // @phpstan-ignore function.notFound
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
}
