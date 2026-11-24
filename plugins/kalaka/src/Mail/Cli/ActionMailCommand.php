<?php

declare(strict_types=1);

namespace Kalaka\Mail\Cli;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Entry\CommandFailure;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Mail\Api\ActionMail;
use Kalaka\Mail\Api\ActionMailSetting;
use Kalaka\Mail\Api\ActionMailSettings;
use Kalaka\Mail\Body\BodyCheck;
use Kalaka\Mail\Body\MailPosts;
use Kalaka\Mail\Catalogue\SetEventMailLayout;
use Kalaka\Mail\Catalogue\UpdateActionMail;

/**
 * `wp kalaka action-mail list|show|on|off|body|layout <event_id> …`: an event's action mails from the command
 * line (mail.md, "Configuration", "Content"), through the use cases `UpdateActionMail` and `SetEventMailLayout`
 * and their checks. It runs as the command line without a user (full access), or, with WP-CLI's `--user`, checked
 * as that user, and is audited like the use cases it runs; `list` and `show` read for full access only.
 * `body` writes the `kalaka_mail` post itself, under the same save check as the editor (`BodyCheck::refusal()`),
 * then assigns it through `UpdateActionMail`. The screen for the same is S34's/S41's.
 */
final class ActionMailCommand
{
    private const FIELDS = ['mail', 'label', 'enabled', 'body_id', 'layout'];

    /** @param ?\Closure(string, list<array<string, string>>, list<string>): void $print prints rows in a format */
    public function __construct(
        private readonly ActionMailSettings $settings = new ActionMailSettings(),
        private readonly MailPosts $posts = new MailPosts(),
        private readonly ?\Closure $print = null,
    ) {
    }

    public static function register(): void
    {
        \WP_CLI::add_command('kalaka action-mail', new self(), [
            'shortdesc' => 'Lists, switches and edits the action mails of an event.',
        ]);
    }

    /**
     * Lists the action mails of an event: whether each is on, its body and whether the event layout wraps it.
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
     * @subcommand list
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function listMails(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args, $assocArgs): void {
            $eventId = CliInput::existingEventId($args);
            CliInput::fullAccess();
            $rows = array_map(
                static fn (ActionMailSetting $mail): array => self::row($mail),
                $this->settings->ofEvent($eventId)->mails
            );
            $format = CliInput::format($assocArgs);
            if ($this->print !== null) {
                ($this->print)($format, $rows, self::FIELDS);

                return;
            }
            // WP-CLI's helper, not installed for the static analysis.
            // @phpstan-ignore function.notFound
            \WP_CLI\Utils\format_items($format, $rows, self::FIELDS);
        });
    }

    /**
     * Prints one action mail as JSON: its state and, when it has one, its body (subject, status and content).
     *
     * ## OPTIONS
     *
     * <event_id>
     * : The event ID.
     *
     * <kind>
     * : The mail: registered, waitlisted, payment_recorded, confirmed or link_regenerated.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function show(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args): void {
            $eventId = CliInput::existingEventId($args);
            CliInput::fullAccess();
            $mail = $this->settings->ofEvent($eventId)->mail(self::kind($args));
            $post = $mail->bodyId === null ? null : get_post($mail->bodyId);
            $data = $mail->toArray() + [
                'body' => $post instanceof \WP_Post
                    ? ['subject' => $post->post_title, 'status' => $post->post_status, 'content' => $post->post_content]
                    : null,
            ];
            $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
            \WP_CLI::line((string) wp_json_encode($data, $flags));
        });
    }

    /**
     * Switches an action mail on. It needs a published body (see `body`); in an event with accounts the
     * "registered" mail's body must carry the set-password link.
     *
     * ## OPTIONS
     *
     * <event_id>
     * : The event ID.
     *
     * <kind>
     * : The mail: registered, waitlisted, payment_recorded, confirmed or link_regenerated.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function on(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args): void {
            $this->switch($args, true);
        });
    }

    /**
     * Switches an action mail off. The "registered" mail of an event with accounts cannot be switched off.
     *
     * ## OPTIONS
     *
     * <event_id>
     * : The event ID.
     *
     * <kind>
     * : The mail: registered, waitlisted, payment_recorded, confirmed or link_regenerated.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function off(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args): void {
            $this->switch($args, false);
        });
    }

    /**
     * Sets the body of an action mail from a file of block markup, and assigns it to the mail. It does not
     * switch the mail on. The body is published and saved under the editor's check; one the check refuses is
     * not saved. The first body of a mail needs its subject (--title); later ones keep it unless one is given.
     *
     * ## OPTIONS
     *
     * <event_id>
     * : The event ID.
     *
     * <kind>
     * : The mail: registered, waitlisted, payment_recorded, confirmed or link_regenerated.
     *
     * --file=<path>
     * : The body's block markup, stored as it is.
     *
     * [--title=<subject>]
     * : The mail's subject, the body's title. Required for a mail with no body yet.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function body(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args, $assocArgs): void {
            $eventId = CliInput::existingEventId($args);
            $action = self::kind($args);
            $content = self::file($assocArgs);
            $title = self::title($assocArgs);
            $actor = CliActor::current();
            AdminAccess::check(
                new UpdateActionMail(),
                ['event_id' => $eventId, 'mail' => $action->value, 'body_id' => null],
                $actor
            );

            $stored = $this->settings->ofEvent($eventId)->mail($action)->bodyId;
            $existing = $stored !== null && $this->posts->eventOf($stored) === $eventId ? $stored : null;
            if ($existing === null && $title === null) {
                throw new CommandFailure('The mail has no body yet: give its subject with --title=<subject>.');
            }
            $message = BodyCheck::refusal($existing ?? 0, $content, $eventId, $action);
            if ($message !== null) {
                $error = new CommandFailure($message);
                throw $error;
            }

            $id = self::save($existing, $eventId, $content, $title);
            if ($existing === null) {
                try {
                    $input = ['event_id' => $eventId, 'mail' => $action->value, 'body_id' => $id];
                    $this->settings->update($input, $actor);
                } catch (\Throwable $e) {
                    wp_delete_post($id, true);
                    throw $e;
                }
            }

            \WP_CLI::success('The body of ' . $action->value . ' is saved (post ' . $id . ').');
        });
    }

    /**
     * Sets the event's layout from a file of raw HTML holding {{content}} exactly once. Stored as it is.
     *
     * ## OPTIONS
     *
     * <event_id>
     * : The event ID.
     *
     * --file=<path>
     * : The layout's HTML.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function layout(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args, $assocArgs): void {
            $eventId = CliInput::eventId($args);
            Runner::current()->run(
                new SetEventMailLayout(),
                ['event_id' => $eventId, 'html' => self::file($assocArgs)],
                CliActor::current()
            );
            \WP_CLI::success('The layout of event ' . $eventId . ' is saved.');
        });
    }

    /** @param list<string> $args */
    private function switch(array $args, bool $enabled): void
    {
        $eventId = CliInput::eventId($args);
        $action = self::kind($args);
        $input = ['event_id' => $eventId, 'mail' => $action->value, 'enabled' => $enabled];
        $this->settings->update($input, CliActor::current());
        \WP_CLI::success($action->value . ' is ' . ($enabled ? 'on' : 'off') . '.');
    }

    /**
     * Writes the body's post, published, and returns its ID.
     *
     * @throws CommandFailure when WordPress does not store it as given
     */
    private static function save(?int $existing, int $eventId, string $content, ?string $title): int
    {
        // Full access is trusted like `unfiltered_html`: the content is stored as written (as the layout is).
        $kses = has_filter('content_save_pre', 'wp_filter_post_kses') !== false;
        if ($kses) {
            kses_remove_filters();
        }
        try {
            $post = [
                'post_type' => MailPosts::POST_TYPE,
                'post_status' => 'publish',
                'post_content' => wp_slash($content),
            ];
            if ($title !== null) {
                $post['post_title'] = wp_slash($title);
            }
            $result = $existing === null
                ? wp_insert_post($post + ['meta_input' => [MailPosts::META_EVENT_ID => $eventId]], true)
                : wp_update_post($post + ['ID' => $existing], true);
        } finally {
            if ($kses) {
                kses_init_filters();
            }
        }
        if ($result instanceof \WP_Error) {
            $error = new CommandFailure('The body is not saved: ' . $result->get_error_message());
            throw $error;
        }
        $saved = get_post($result);
        $stored = $saved instanceof \WP_Post && $saved->post_status === 'publish' && $saved->post_content === $content;
        if (!$stored) {
            if ($existing === null) {
                wp_delete_post($result, true);
            }
            throw new CommandFailure('The body is not saved as given: WordPress refused it.');
        }

        return $result;
    }

    /**
     * @param list<string> $args
     * @throws CommandFailure when the kind is none of the catalogue's
     */
    private static function kind(array $args): ActionMail
    {
        $given = $args[1] ?? '';
        $action = ActionMail::tryFrom($given);
        if ($action === null) {
            $error = new CommandFailure(
                'The mail must be one of: ' . implode(', ', array_column(ActionMail::cases(), 'value')) . '.'
            );
            throw $error;
        }

        return $action;
    }

    /**
     * @param array<string, mixed> $assocArgs
     * @throws CommandFailure when there is no readable, non-empty file
     */
    private static function file(array $assocArgs): string
    {
        $path = $assocArgs['file'] ?? null;
        if (!is_string($path) || $path === '') {
            throw new CommandFailure('Give the content with --file=<path>.');
        }
        if (!is_file($path) || !is_readable($path)) {
            $error = new CommandFailure('The file cannot be read: ' . $path);
            throw $error;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file given on the command line
        $content = (string) file_get_contents($path);
        if (trim($content) === '') {
            $error = new CommandFailure('The file is empty: ' . $path);
            throw $error;
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $assocArgs
     * @throws CommandFailure when a title is given but is not a text
     */
    private static function title(array $assocArgs): ?string
    {
        if (!array_key_exists('title', $assocArgs)) {
            return null;
        }
        $title = $assocArgs['title'];
        if (!is_string($title) || trim($title) === '') {
            throw new CommandFailure('The subject given with --title must not be empty.');
        }

        return $title;
    }

    /** @return array<string, string> */
    private static function row(ActionMailSetting $mail): array
    {
        return [
            'mail' => $mail->action->value,
            'label' => $mail->action->label(),
            'enabled' => $mail->enabled ? 'yes' : 'no',
            'body_id' => $mail->bodyId === null ? '' : (string) $mail->bodyId,
            'layout' => $mail->layout ? 'yes' : 'no',
        ];
    }
}
