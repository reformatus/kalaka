<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Cli;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Entry\CommandFailure;
use Kalaka\Foundation\Api\Error\KalakaException;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Pricing\Api\MoneySettings;
use Kalaka\Pricing\Api\PlainAmount;
use Kalaka\Pricing\Api\PreviewRow;
use Kalaka\Pricing\SetFixedPrice;

/**
 * `wp kalaka money-settings show|preview|apply <event_id>`: an event's money settings as JSON, and a change
 * of them, preview → confirm → apply, through `Api\MoneySettings` (money.md, "Money settings apply"). It runs
 * as the command line without a user (full access), or, with WP-CLI's `--user`, checked as that user, and is
 * audited like the use cases it runs. Every verb is checked as the write of the same data, the fixed price's
 * save (`SetFixedPrice`, full access only), before it reads or writes anything. The screen for the same is
 * S72's.
 */
final class MoneySettingsCommand
{
    private const FIELDS = ['registration', 'status', 'payer', 'now', 'would_owe', 'problem'];

    /** @param ?\Closure(string, list<array<string, string>>, list<string>): void $print prints rows in a format */
    public function __construct(
        private readonly MoneySettings $settings = new MoneySettings(),
        private readonly SettingsDocument $document = new SettingsDocument(),
        private readonly ?\Closure $print = null,
    ) {
    }

    public static function register(): void
    {
        \WP_CLI::add_command('kalaka money-settings', new self(), [
            'shortdesc' => 'Shows, previews and applies the money settings of an event.',
        ]);
    }

    /**
     * Prints the event's current money settings as JSON: the fixed price and each component's settings.
     *
     * ## OPTIONS
     *
     * <event_id>
     * : The event ID.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function show(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args): void {
            $eventId = self::eventId($args);
            self::check($eventId, self::actor());
            \WP_CLI::line(self::json($this->document->show($eventId)));
        });
    }

    /**
     * Shows what each registration's payers owe now and would owe under the settings in a file. Writes nothing.
     *
     * ## OPTIONS
     *
     * <event_id>
     * : The event ID.
     *
     * --file=<json>
     * : The settings, in the shape `show` prints. What the file leaves out stays as it is.
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
    public function preview(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args, $assocArgs): void {
            $eventId = self::eventId($args);
            self::check($eventId, self::actor());
            $rows = $this->settings->preview($eventId, $this->document->proposed($eventId, self::file($assocArgs)));
            $this->printRows($rows, self::format($assocArgs));
        });
    }

    /**
     * Saves the settings in a file, then re-evaluates every registered and waitlisted registration. It shows
     * the preview and asks first.
     *
     * ## OPTIONS
     *
     * <event_id>
     * : The event ID.
     *
     * --file=<json>
     * : The settings, in the shape `show` prints. What the file leaves out stays as it is.
     *
     * [--yes]
     * : Apply without asking.
     *
     * [--format=<format>]
     * : How the preview is printed: table, json or csv.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function apply(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args, $assocArgs): void {
            $eventId = self::eventId($args);
            $actor = self::actor();
            self::check($eventId, $actor);
            $proposed = $this->document->proposed($eventId, self::file($assocArgs));
            $rows = $this->settings->preview($eventId, $proposed);
            $this->printRows($rows, self::format($assocArgs));
            \WP_CLI::confirm('Apply these settings to ' . count($rows) . ' registrations?', $assocArgs);

            $report = $this->settings->apply($eventId, $proposed, $actor);

            \WP_CLI::line('Re-evaluated: ' . count($report->done()) . '. Failed: ' . count($report->failed()) . '.');
            foreach ($report->failed() as $item) {
                $why = $item->failure === null ? '' : self::describe($item->failure);
                \WP_CLI::line('Failed ' . $item->registration->code . ': ' . $why);
            }
            if ($report->failed() !== []) {
                $error = new CommandFailure(
                    'The settings are saved, but some registrations failed. Each converges on its next evaluation;'
                    . ' running apply again retries them now.'
                );
                throw $error;
            }
            \WP_CLI::success('The settings are applied.');
        });
    }

    /** @param list<PreviewRow> $rows */
    private function printRows(array $rows, string $format): void
    {
        $items = [];
        foreach ($rows as $row) {
            $base = ['registration' => $row->registration->code, 'status' => $row->status->value];
            if ($row->problem !== null) {
                $items[] = $base + [
                    'payer' => '',
                    'now' => '',
                    'would_owe' => '',
                    'problem' => self::describe($row->problem),
                ];
                continue;
            }
            $payers = array_values(array_unique([...array_keys($row->before), ...array_keys($row->after)]));
            foreach ($payers as $payer) {
                $items[] = $base + [
                    'payer' => (string) $payer,
                    'now' => self::amount($row->before[$payer] ?? null),
                    'would_owe' => self::amount($row->after[$payer] ?? null),
                    'problem' => '',
                ];
            }
        }
        if ($this->print !== null) {
            ($this->print)($format, $items, self::FIELDS);

            return;
        }
        \WP_CLI\Utils\format_items($format, $items, self::FIELDS);
    }

    /** The command line without a user, or WP-CLI's `--user`. */
    private static function actor(): CliActor
    {
        $current = get_current_user_id();

        return new CliActor($current > 0 ? $current : null);
    }

    /**
     * The same permission check the apply's first use case makes, before anything is read (a refused actor
     * learns nothing): the fixed price's save, full access only.
     *
     * @throws \Kalaka\Foundation\Api\Error\PermissionDenied
     */
    private static function check(int $eventId, CliActor $actor): void
    {
        AdminAccess::check(new SetFixedPrice(), ['event_id' => $eventId], $actor);
    }

    /** The operator's text of an error: a Kaláka error's own, anything else as a system failure with its ID. */
    private static function describe(\Throwable $error): string
    {
        return $error instanceof KalakaException
            ? $error->operatorMessage()
            : (new SystemFailure(null, $error))->operatorMessage();
    }

    private static function amount(?Money $money): string
    {
        return $money === null ? '0' : PlainAmount::of($money);
    }

    /** @param list<string> $args */
    private static function eventId(array $args): int
    {
        $given = $args[0] ?? '';
        if (preg_match('/\A[1-9][0-9]*\z/', $given) !== 1) {
            $error = new CommandFailure('The event ID must be a positive whole number.');
            throw $error;
        }

        return (int) $given;
    }

    /**
     * @param array<string, mixed> $assocArgs
     * @throws CommandFailure when there is no readable file with JSON in it
     */
    private static function file(array $assocArgs): mixed
    {
        $path = $assocArgs['file'] ?? null;
        if (!is_string($path) || $path === '') {
            $error = new CommandFailure('Give the settings with --file=<json>.');
            throw $error;
        }
        if (!is_file($path) || !is_readable($path)) {
            $error = new CommandFailure('The file cannot be read: ' . $path);
            throw $error;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file given on the command line
        $content = file_get_contents($path);
        try {
            return json_decode((string) $content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $error = new CommandFailure('The file is not valid JSON: ' . $e->getMessage(), $e);
            throw $error;
        }
    }

    /** @param array<string, mixed> $assocArgs */
    private static function format(array $assocArgs): string
    {
        $format = $assocArgs['format'] ?? 'table';
        if (!in_array($format, ['table', 'json', 'csv'], true)) {
            $error = new CommandFailure('The format must be table, json or csv.');
            throw $error;
        }

        return $format;
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): string
    {
        $data['components'] = (object) $data['components'];

        return (string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
