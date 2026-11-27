<?php

declare(strict_types=1);

namespace Kalaka\Mail\Cli;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Entry\CommandFailure;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Mail\Api\ManualMails;
use Kalaka\Mail\Manual\SendManualMail;
use Kalaka\Mail\Manual\UnknownRegistrationCode;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * `wp kalaka mail send`: sends a chosen mail body to chosen registrations of an event (mail.md, "Five kinds
 * of mail", Manual). A thin entry point: it changes only through the use case
 * `SendManualMail` (`ManualMails::send()`), as the command line (full access) or as WP-CLI's `--user`, checked
 * by that user's grants on the codes before anything about them is printed. Registered beside `MailCommand`'s
 * commands, so either can merge first. Mail is never sent here: the batch only queues deliveries.
 */
final class ManualMailCommand
{
    /**
     * @param ?\Closure(string, array<string, mixed>): void $confirm asks to go on; ends the command when declined
     */
    public function __construct(
        private readonly ManualMails $mails = new ManualMails(),
        private readonly ?\Closure $confirm = null,
    ) {
    }

    public static function register(): void
    {
        \WP_CLI::add_command('kalaka mail send', new self());
    }

    /**
     * Sends a chosen mail body to chosen registrations of an event.
     *
     * Queues the body (a published `kalaka_mail` post of the event, an action mail's included) for each
     * registration code, as one send batch. The sender sends it later, under the pause and the throttle, and a
     * mail of an unpublished event waits until it is published. A registration that is not registered
     * (withdrawn, removed or waitlisted) is skipped and listed; a code that is no registration of the event
     * refuses the whole send. Shows what it would do and asks first (`--yes` skips); `--dry-run` only shows. A
     * rerun with the same `--key` queues nothing and names the first batch; without a key every run is a new batch, a second copy. With `--user`
     * the user's grants must hold every code.
     *
     * ## OPTIONS
     *
     * <event_id>
     * : The event ID.
     *
     * --body=<post_id>
     * : The `kalaka_mail` post to send.
     *
     * [--codes=<codes>]
     * : Registration codes, comma-separated.
     *
     * [--codes-file=<path>]
     * : A file of codes, one per line or comma-separated (the code column of the grid's copy).
     *
     * [--key=<token>]
     * : The repeat guard: 8 to 64 letters, digits, dashes and underscores.
     *
     * [--dry-run]
     * : Show what would happen and stop.
     *
     * [--yes]
     * : Answer yes to the confirmation.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args, $assocArgs): void {
            $eventId = CliInput::existingEventId($args);
            $input = [
                'event_id' => $eventId,
                'body_id' => self::body($assocArgs),
                'codes' => self::codes($assocArgs, $eventId),
            ];
            if (isset($assocArgs['key'])) {
                $input['request_key'] = (string) $assocArgs['key'];
            }
            // The access check first: a user holding a grant learns nothing about codes outside their views.
            $actor = CliActor::current();
            AdminAccess::check(new SendManualMail(), $input, $actor);

            $preview = $this->mails->preview($eventId, $input['codes']);
            $skipped = array_map(
                static fn (array $row): string => $row['code'] . ' (' . $row['reason'] . ')',
                $preview['skipped']
            );
            \WP_CLI::line(
                count($preview['queue']) . ' to queue, ' . count($preview['skipped'])
                . ' skipped (withdrawn/removed/waitlisted)'
                . ($skipped === [] ? '' : ': ' . implode(', ', $skipped))
                . ', unknown: ' . ($preview['unknown'] === [] ? 'none' : implode(', ', $preview['unknown']))
            );
            if (isset($assocArgs['dry-run'])) {
                \WP_CLI::line('Dry run: nothing was queued.');

                return;
            }
            try {
                SendManualMail::refuseUnknown($preview);
            } catch (UnknownRegistrationCode) {
                // The use case's own check, run ahead so the refusal comes before the question.
                throw new CommandFailure('Nothing was queued: a code is no registration of the event.');
            }
            $this->ask(
                'Queue this mail for ' . count($preview['queue']) . ' registrations of event ' . $eventId . '?',
                $assocArgs
            );

            $result = $this->mails->send($input, $actor);
            \WP_CLI::success(
                ($result['repeat'] ? 'The same key was used before: batch ' : 'Batch ')
                . $result['batch_id'] . ($result['repeat'] ? ' was kept, nothing new was queued.' : ' queued.')
                . ' Queued: ' . $result['queued'] . ', skipped: ' . count($result['skipped']) . '.'
            );
            if ($result['paused']) {
                \WP_CLI::warning(
                    'Sending is paused: the mail waits until a person resumes it (wp kalaka mail status).'
                );
            }
            if ($result['held']) {
                \WP_CLI::warning('Event ' . $eventId . ' is not published: its mail is held until it is published.');
            }
        });
    }

    /** @param array<string, mixed> $assocArgs */
    private function ask(string $question, array $assocArgs): void
    {
        if ($this->confirm !== null) {
            ($this->confirm)($question, $assocArgs);

            return;
        }
        // WP-CLI's helper, not in the stubs for the static analysis.
        // @phpstan-ignore staticMethod.notFound
        \WP_CLI::confirm($question, $assocArgs);
    }

    /** @param array<string, mixed> $assocArgs */
    private static function body(array $assocArgs): int
    {
        $given = $assocArgs['body'] ?? '';
        if (!is_string($given) || preg_match('/\A[1-9][0-9]*\z/', $given) !== 1) {
            throw new CommandFailure('Give the mail body with --body=<post_id>.');
        }

        return (int) $given;
    }

    /**
     * The codes of `--codes` or `--codes-file`, each once, in the order given.
     *
     * @param array<string, mixed> $assocArgs
     * @return list<string>
     * @throws CommandFailure when none are given, or one is not a reference code
     */
    private static function codes(array $assocArgs, int $eventId): array
    {
        $text = $assocArgs['codes'] ?? null;
        $path = $assocArgs['codes-file'] ?? null;
        if (is_string($text) === is_string($path)) {
            throw new CommandFailure('Give the codes with --codes=<a,b,c> or --codes-file=<path>, not both.');
        }
        if (is_string($path)) {
            if (!is_file($path) || !is_readable($path)) {
                $error = new CommandFailure('The file cannot be read: ' . $path);
                throw $error;
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file given on the command line
            $text = (string) file_get_contents($path);
        }
        $codes = [];
        $invalid = [];
        foreach (preg_split('/[\s,;]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $code) {
            try {
                $codes[(new RegistrationKey($eventId, $code))->code] = true;
            } catch (InvalidInput) {
                $invalid[] = $code;
            }
        }
        if ($invalid !== []) {
            $error = new CommandFailure('Not a registration code: ' . implode(', ', $invalid) . '.');
            throw $error;
        }
        if ($codes === []) {
            throw new CommandFailure('No registration codes were given.');
        }

        return array_map('strval', array_keys($codes));
    }
}
