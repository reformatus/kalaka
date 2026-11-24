<?php

declare(strict_types=1);

namespace Kalaka\Events\Cli;

use Kalaka\Events\Api\AccountMode;
use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\Events;
use Kalaka\Events\Api\EventState;
use Kalaka\Events\ArchiveEvent;
use Kalaka\Events\CreateEvent;
use Kalaka\Events\PublishEvent;
use Kalaka\Events\UnpublishEvent;
use Kalaka\Forms\Api\Forms;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Entry\CommandFailure;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Api\UseCase\UseCase;

/**
 * `wp kalaka event create|update|show|list|publish|unpublish|archive`: an event's setup from the command line
 * (events-and-forms.md, "Events", "Event lifecycle"), through the use cases of the module and their checks. It
 * runs as the command line without a user (full access), or, with WP-CLI's `--user`, checked as that user, and
 * is audited like the use cases it runs. The screen for the same is S72's. Nothing here re-evaluates a
 * registration (a point or a setting is read at the time), so no command previews or asks: `update --dry-run`
 * only lists the changes it would make. [UNAPPROVED N1]
 */
final class EventCommand
{
    private const LIST_FIELDS = [
        'id', 'name', 'state', 'capacity', 'registration_opens_at', 'registration_closes_at', 'starts_at', 'ends_at',
    ];

    /** @param ?\Closure(string, list<array<string, string>>, list<string>): void $print prints rows in a format */
    public function __construct(
        private readonly Events $events = new Events(),
        private readonly ?\Closure $print = null,
    ) {
    }

    public static function register(): void
    {
        \WP_CLI::add_command('kalaka event', new self(), [
            'shortdesc' => 'Creates, sets up, lists, publishes and archives events.',
        ]);
    }

    /**
     * Creates a draft event with a name. Its points, capacity and settings come next, with `update`.
     *
     * ## OPTIONS
     *
     * <name>
     * : The event's name.
     *
     * [--porcelain]
     * : Print only the new event's ID.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function create(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args, $assocArgs): void {
            $name = trim($args[0] ?? '');
            if ($name === '') {
                throw new CommandFailure('Give the event\'s name.');
            }
            $id = (int) Runner::current()->run(new CreateEvent(), ['name' => $name], self::actor());
            if (!empty($assocArgs['porcelain'])) {
                \WP_CLI::line((string) $id);

                return;
            }
            \WP_CLI::success('The event is created as a draft (event ' . $id . ').');
        });
    }

    /**
     * Changes an event from a JSON file in the shape `show --format=json` prints. What the file leaves out stays as
     * it is; a key it gives replaces that setting (`null` clears it; "points" replaces the whole custom list, a
     * point with its "id" is kept). Times are local, like 2026-11-30 09:00. An archived event is read-only.
     *
     * ## OPTIONS
     *
     * <event_id>
     * : The event ID.
     *
     * --file=<json>
     * : The changes: name, capacity, registration_opens_at, registration_closes_at, starts_at, ends_at, points,
     *   account_mode (mandatory, optional or off), payment_due_days, transfer_beneficiary, transfer_account and
     *   transfer_reference_prefix. The read-only keys of `show` may stay in it when they still match.
     *
     * [--dry-run]
     * : List the changes without saving.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function update(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args, $assocArgs): void {
            $event = $this->event($args);
            $current = EventDocument::of($event);
            [$proposed, $given] = EventDocument::proposed($current, self::file($assocArgs));
            $changes = EventDocument::changes($current, $proposed);
            if ($changes === []) {
                \WP_CLI::success('Nothing to change.');

                return;
            }
            $this->refuseWithoutAccountBlock($event, $proposed);
            foreach ($changes as $key => [$before, $after]) {
                \WP_CLI::line($key . ': ' . self::text($before) . ' -> ' . self::text($after));
            }
            if (!empty($assocArgs['dry-run'])) {
                \WP_CLI::success('Nothing is saved (dry run).');

                return;
            }

            $this->events->update(EventDocument::input($proposed, $given, $event->id, $event->version), self::actor());
            \WP_CLI::success('The event is saved (version ' . ($event->version + 1) . ').');
        });
    }

    /**
     * Prints one event: its state, points, capacity and settings.
     *
     * ## OPTIONS
     *
     * <event_id>
     * : The event ID.
     *
     * [--format=<format>]
     * : table or json. The JSON is what `update --file` reads.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function show(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args, $assocArgs): void {
            $document = EventDocument::of($this->event($args));
            $format = self::format($assocArgs, ['table', 'json']);
            if ($format === 'json') {
                \WP_CLI::line((string) wp_json_encode(
                    $document,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ));

                return;
            }
            $rows = [];
            foreach ($document as $field => $value) {
                $rows[] = ['field' => $field, 'value' => self::text($value)];
            }
            $this->printRows('table', $rows, ['field', 'value']);
        });
    }

    /**
     * Lists every event, whatever its state.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, json or csv.
     * ---
     * default: table
     * ---
     *
     * @subcommand list
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function listEvents(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($assocArgs): void {
            $format = self::format($assocArgs, ['table', 'json', 'csv']);
            $rows = [];
            foreach ($this->events->all() as $event) {
                $document = EventDocument::of($event);
                $row = [];
                foreach (self::LIST_FIELDS as $field) {
                    $row[$field] = self::text($document[$field]);
                }
                $rows[] = $row;
            }
            $this->printRows($format, $rows, self::LIST_FIELDS);
        });
    }

    /**
     * Publishes a draft or unpublished event. It needs a name, the four core points and a capacity; a form opens
     * at the registration point by itself.
     *
     * ## OPTIONS
     *
     * <event_id>
     * : The event ID.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function publish(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args): void {
            $event = $this->event($args);
            if (!in_array($event->state(), [EventState::Draft, EventState::Unpublished], true)) {
                throw new CommandFailure('Only a draft or unpublished event can be published.');
            }
            $document = EventDocument::of($event);
            $missing = array_keys(array_filter(
                array_intersect_key($document, array_flip([
                    'capacity', 'registration_opens_at', 'registration_closes_at', 'starts_at', 'ends_at',
                ])),
                static fn (mixed $value): bool => $value === null
            ));
            if ($missing !== []) {
                $error = new CommandFailure('The event cannot be published without: ' . implode(', ', $missing) . '.');
                throw $error;
            }
            $this->transition(new PublishEvent(), $event);
            \WP_CLI::success('The event is published.');
        });
    }

    /**
     * Unpublishes a published event: its forms, participant blocks, payments and mail stop (queued action mails
     * stay queued). Publishing it again undoes this.
     *
     * ## OPTIONS
     *
     * <event_id>
     * : The event ID.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function unpublish(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args): void {
            $event = $this->event($args);
            if ($event->state() !== EventState::Published) {
                throw new CommandFailure('Only a published event can be unpublished.');
            }
            $this->transition(new UnpublishEvent(), $event);
            \WP_CLI::success('The event is unpublished.');
        });
    }

    /**
     * Archives an unpublished event. An archived event is read-only for good, so it needs --yes.
     *
     * ## OPTIONS
     *
     * <event_id>
     * : The event ID.
     *
     * [--yes]
     * : Archive without being stopped: the event becomes read-only and cannot be published again.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function archive(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args, $assocArgs): void {
            $event = $this->event($args);
            if ($event->state() !== EventState::Unpublished) {
                throw new CommandFailure('Only an unpublished event can be archived.');
            }
            if (empty($assocArgs['yes'])) {
                $error = new CommandFailure(
                    'Archiving makes the event read-only for good. Run it again with --yes to archive it.'
                );
                throw $error;
            }
            $this->transition(new ArchiveEvent(), $event);
            \WP_CLI::success('The event is archived.');
        });
    }

    private function transition(UseCase $useCase, Event $event): void
    {
        Runner::current()->run($useCase, ['id' => $event->id, 'version' => $event->version], self::actor());
    }

    /**
     * Names the forms that would stop the mode change, ahead of the run, so the refusal says which.
     *
     * @param array<string, mixed> $proposed
     * @throws CommandFailure when the mode changes to one with accounts and a published form lacks the block
     */
    private function refuseWithoutAccountBlock(Event $event, array $proposed): void
    {
        $mode = AccountMode::from((string) $proposed['account_mode']);
        if ($mode === $event->accountMode || $mode === AccountMode::Off) {
            return;
        }
        $lacking = (new Forms())->withoutAccountBlock($event->id, $mode);
        if ($lacking !== []) {
            $error = new CommandFailure(
                'The account mode stays as it is: these published registration forms have no account block: '
                . implode(', ', $lacking) . '. Add the block in the form editor first.'
            );
            throw $error;
        }
    }

    /** @param list<string> $args */
    private function event(array $args): Event
    {
        $given = $args[0] ?? '';
        if (preg_match('/\A[1-9][0-9]*\z/', $given) !== 1) {
            throw new CommandFailure('The event ID must be a positive whole number.');
        }

        $event = $this->events->find((int) $given);
        if ($event === null) {
            $error = new CommandFailure('There is no event ' . $given . '.');
            throw $error;
        }

        return $event;
    }

    /**
     * @param array<string, mixed> $assocArgs
     * @throws CommandFailure when there is no readable file with JSON in it
     */
    private static function file(array $assocArgs): mixed
    {
        $path = $assocArgs['file'] ?? null;
        if (!is_string($path) || $path === '') {
            throw new CommandFailure('Give the changes with --file=<json>.');
        }
        if (!is_file($path) || !is_readable($path)) {
            $error = new CommandFailure('The file cannot be read: ' . $path);
            throw $error;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file given on the command line
        $content = (string) file_get_contents($path);
        try {
            return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $error = new CommandFailure('The file is not valid JSON: ' . $e->getMessage(), $e);
            throw $error;
        }
    }

    /**
     * @param array<string, mixed> $assocArgs
     * @param list<string> $allowed
     */
    private static function format(array $assocArgs, array $allowed): string
    {
        $format = $assocArgs['format'] ?? 'table';
        if (!is_string($format) || !in_array($format, $allowed, true)) {
            $last = array_pop($allowed);
            $error = new CommandFailure('The format must be ' . implode(', ', $allowed) . ' or ' . $last . '.');
            throw $error;
        }

        return $format;
    }

    /**
     * @param list<array<string, string>> $rows
     * @param list<string> $fields
     */
    private function printRows(string $format, array $rows, array $fields): void
    {
        if ($this->print !== null) {
            ($this->print)($format, $rows, $fields);

            return;
        }
        // WP-CLI's helper, not installed for the static analysis.
        // @phpstan-ignore function.notFound
        \WP_CLI\Utils\format_items($format, $rows, $fields);
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'yes' : 'no',
            is_scalar($value) => (string) $value,
            default => (string) wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }

    private static function actor(): CliActor
    {
        $current = get_current_user_id();

        return new CliActor($current > 0 ? $current : null);
    }
}
