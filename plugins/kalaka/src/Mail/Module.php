<?php

declare(strict_types=1);

namespace Kalaka\Mail;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Module\Schema;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Mail\Api\MailQueue;
use Kalaka\Mail\Body\ActionMailComposer;
use Kalaka\Mail\Body\MailBlockGate;
use Kalaka\Mail\Body\MailPosts;
use Kalaka\Mail\Body\MailRestController;
use Kalaka\Mail\Pause\PauseCause;
use Kalaka\Mail\Pause\PauseWarning;
use Kalaka\Mail\Pause\RequestState;

final class Module extends AbstractModule
{
    /**
     * mail.md, "Words", "Five kinds of mail" and "Failures". Times are UTC `datetime(6)`; `error` is the
     * text of the latest error, a `longtext` for the same reason as the audit's JSON columns. `once`
     * is the once-only key; `event_status` serves the sender's candidates and the per-event counts;
     * `claimed_at` serves the site-wide hourly budget; `status_id` the site-wide pending count and the
     * clearing of the queue.
     */
    private const DELIVERIES = <<<'SQL'
CREATE TABLE {prefix}kalaka_deliveries (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id bigint(20) unsigned NOT NULL,
  mail varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  code varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  cause_type varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  cause_key varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  version int(10) unsigned NOT NULL,
  kind varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  status varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  attempts int(10) unsigned NOT NULL DEFAULT 0,
  queued_at datetime(6) NOT NULL,
  claimed_at datetime(6) DEFAULT NULL,
  sent_at datetime(6) DEFAULT NULL,
  mail_version varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  error longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY once (event_id,mail,code,cause_type,cause_key),
  KEY event_status (event_id,status),
  KEY claimed_at (claimed_at),
  KEY status_id (status,id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /**
     * mail.md, "Pausing": the sender's one state row (`id` 1), with the pause, the errors in a row and the
     * hourly budget. `error` is the error of the delivery that paused sending.
     */
    private const STATES = <<<'SQL'
CREATE TABLE {prefix}kalaka_mail_states (
  id tinyint(3) unsigned NOT NULL,
  version int(10) unsigned NOT NULL,
  paused_at datetime(6) DEFAULT NULL,
  cause varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  paused_by bigint(20) unsigned DEFAULT NULL,
  delivery_id bigint(20) unsigned DEFAULT NULL,
  delivery_event_id bigint(20) unsigned DEFAULT NULL,
  error longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  errors_in_row int(10) unsigned NOT NULL DEFAULT 0,
  hourly_budget int(10) unsigned NOT NULL DEFAULT 240,
  PRIMARY KEY  (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /**
     * mail.md, "Configuration": one event's setting of one action mail (`mail` is the action's key); a missing
     * row is the default (off, no body, wrapped by the layout). `body_id` is the body's `kalaka_mail` post.
     * // [UNAPPROVED N16] the per-event mail settings live in two Mail-owned tables.
     */
    private const ACTION_MAILS = <<<'SQL'
CREATE TABLE {prefix}kalaka_action_mails (
  event_id bigint(20) unsigned NOT NULL,
  mail varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  version int(10) unsigned NOT NULL,
  enabled tinyint(1) unsigned NOT NULL DEFAULT 0,
  body_id bigint(20) unsigned DEFAULT NULL,
  layout tinyint(1) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY  (event_id,mail)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /** mail.md, "Configuration": an event's layout, the `kalaka_mail` post that wraps its mails. */
    private const LAYOUTS = <<<'SQL'
CREATE TABLE {prefix}kalaka_mail_layouts (
  event_id bigint(20) unsigned NOT NULL,
  version int(10) unsigned NOT NULL,
  layout_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (event_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /** The mail blocks, in `Blocks/`: the body's place in a layout. */
    private const BLOCKS = ['mail-content'];

    private ?Sender $sender = null;

    public function id(): string
    {
        return 'mail';
    }

    public function requiresApi(): string
    {
        return '>=' . KALAKA_VERSION;
    }

    public function schema(): Schema
    {
        return new Schema(3, [self::DELIVERIES, self::STATES, self::ACTION_MAILS, self::LAYOUTS]);
    }

    public function contribute(Contributions $to): void
    {
        $state = new RequestState();
        $to->siteWarning(new PauseWarning(PauseCause::Errors, $state));
        $to->siteWarning(new PauseWarning(PauseCause::Manual, $state));
        $to->periodicStep($this->sender());
    }

    public function boot(): void
    {
        $sender = $this->sender();
        add_action(MailQueue::SEND_HOOK, static function () use ($sender): void {
            Boundary::background('mail sender', static fn (): mixed => $sender->send());
        });
        add_action('init', [$this, 'register']);
    }

    /** @internal the `init` callback */
    public function register(): void
    {
        $this->registerPostType();
        $this->registerMeta();
        // [UNAPPROVED N12] the block `kalaka/mail-content`; [UNAPPROVED N20] its title.
        foreach (self::BLOCKS as $name) {
            register_block_type(__DIR__ . '/Blocks/' . $name);
        }

        // A mail's version is a revision ID, so revisions of mail documents are never pruned.
        add_filter('wp_' . MailPosts::POST_TYPE . '_revisions_to_keep', static fn (): int => -1);
        add_action('wp_after_insert_post', [new MailPosts(), 'ensureRevision'], 10, 2);
        add_filter('allowed_block_types_all', [MailBlockGate::class, 'filter'], 20, 2);
    }

    /**
     * Mail bodies and layouts are edited in the block editor (mail.md, "Content") by full access only, like
     * forms: every capability, REST reads included (`MailRestController`), needs `kalaka_full_access`. No
     * admin menu item and no labels of its own.
     * // [UNAPPROVED N12] `kalaka_mail` is exposed like `kalaka_form`.
     */
    private function registerPostType(): void
    {
        $full = AdminAccess::FULL_ACCESS;
        register_post_type(MailPosts::POST_TYPE, [
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => true,
            'rest_controller_class' => MailRestController::class,
            'supports' => ['title', 'editor', 'revisions', 'custom-fields'],
            'map_meta_cap' => false,
            'capabilities' => [
                'edit_post' => $full,
                'read_post' => $full,
                'delete_post' => $full,
                'edit_posts' => $full,
                'edit_others_posts' => $full,
                'delete_posts' => $full,
                'publish_posts' => $full,
                'read_private_posts' => $full,
                'create_posts' => $full,
            ],
        ]);
    }

    /**
     * The event of a mail document, as post meta in REST for full access only; called by `register()`.
     *
     * @internal
     */
    public function registerMeta(): void
    {
        register_post_meta(MailPosts::POST_TYPE, MailPosts::META_EVENT_ID, [
            'type' => 'integer',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => static fn (bool $allowed, string $metaKey, int $postId, int $userId): bool
                => user_can($userId, AdminAccess::FULL_ACCESS),
        ]);
    }

    private function sender(): Sender
    {
        // The action mails' composer: the sender runs (mail.md, "Sending").
        return $this->sender ??= new Sender(static fn (): Runner => Runner::current(), new ActionMailComposer());
    }
}
