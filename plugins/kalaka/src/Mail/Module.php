<?php

declare(strict_types=1);

namespace Kalaka\Mail;

use Kalaka\Admin\Api\Abilities;
use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Forms\Api\BlockHost;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Module\Schema;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Mail\Api\MailQueue;
use Kalaka\Mail\Api\ManualMails;
use Kalaka\Mail\Body\BodyCheck;
use Kalaka\Mail\Body\BodyInUse;
use Kalaka\Mail\Body\KindComposer;
use Kalaka\Mail\Body\MailBlocks;
use Kalaka\Mail\Body\MailPosts;
use Kalaka\Mail\Body\MailRestController;
use Kalaka\Mail\Catalogue\SetEventMailLayout;
use Kalaka\Mail\Catalogue\UpdateActionMail;
use Kalaka\Mail\Cli\ActionMailCommand;
use Kalaka\Mail\Cli\MailCommand;
use Kalaka\Mail\Cli\ManualMailBatchesCommand;
use Kalaka\Mail\Cli\ManualMailCommand;
use Kalaka\Mail\Manual\SendManualMail;
use Kalaka\Mail\Pause\BudgetSetting;
use Kalaka\Mail\Pause\PauseCause;
use Kalaka\Mail\Pause\PauseMail;
use Kalaka\Mail\Pause\PauseWarning;
use Kalaka\Mail\Pause\RequestState;
use Kalaka\Mail\Pause\ResumeMail;
use Kalaka\Mail\Pause\SetMailBudget;
use Kalaka\Mail\Rest\MailRoutes;

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
     * row is the default (off, no body). `body_id` is the body's `kalaka_mail` post, one
     * to one (mail.md, "Words"): a body serves at most one action mail.
     */
    private const ACTION_MAILS = <<<'SQL'
CREATE TABLE {prefix}kalaka_action_mails (
  event_id bigint(20) unsigned NOT NULL,
  mail varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  version int(10) unsigned NOT NULL,
  enabled tinyint(1) unsigned NOT NULL DEFAULT 0,
  body_id bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (event_id,mail),
  UNIQUE KEY body_id (body_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /**
     * mail.md, "Configuration": an event's layout, the raw HTML that wraps its mails, stored as written.
     */
    private const LAYOUTS = <<<'SQL'
CREATE TABLE {prefix}kalaka_mail_layouts (
  event_id bigint(20) unsigned NOT NULL,
  version int(10) unsigned NOT NULL,
  html longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  PRIMARY KEY  (event_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /**
     * mail.md, "Five kinds of mail", Manual: a send batch, the cause of its deliveries (`cause_type`
     * `mail_batch`, `cause_key` its `id`). `request_key` is the caller's repeat guard, unique within the event
     * (several batches may have none). The queued and skipped counts are kept so a list needs no join.
     */
    private const BATCHES = <<<'SQL'
CREATE TABLE {prefix}kalaka_mail_batches (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id bigint(20) unsigned NOT NULL,
  version int(10) unsigned NOT NULL,
  body_id bigint(20) unsigned NOT NULL,
  request_key varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  created_at datetime(6) NOT NULL,
  created_by bigint(20) unsigned DEFAULT NULL,
  queued int(10) unsigned NOT NULL DEFAULT 0,
  skipped int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY request_key (event_id,request_key),
  KEY event_id (event_id,id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /**
     * The audience of a send batch, frozen at the send: registration codes and what became of each, never an
     * address, a name or rendered content.
     */
    private const BATCH_MEMBERS = <<<'SQL'
CREATE TABLE {prefix}kalaka_mail_batch_members (
  batch_id bigint(20) unsigned NOT NULL,
  code varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  event_id bigint(20) unsigned NOT NULL,
  outcome varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  PRIMARY KEY  (batch_id,code)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /**
     * Kaláka's blocks a mail body may hold besides its own: the condition blocks (mail.md, "Content").
     */
    public const BODY_BLOCKS = [
        'kalaka/if',
        'kalaka/else-if',
        'kalaka/else',
    ];

    /** The mail body's own blocks, which no other editor offers (`BlockHost::$hostOnly`): the two money blocks. */
    public const OWN_BLOCKS = [
        MailBlocks::MONEY_BLOCK,
        MailBlocks::TRANSFER_BLOCK,
    ];

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
        return new Schema(5, [
            self::DELIVERIES,
            self::STATES,
            self::ACTION_MAILS,
            self::LAYOUTS,
            self::BATCHES,
            self::BATCH_MEMBERS,
        ]);
    }

    /**
     * The per-event actions grants may carry (foundation.md, "Views and grants"): sending a manual mail to
     * registrations inside the grant's view (`kalaka/send-manual-mail`).
     */
    public function capabilities(): array
    {
        return [ManualMails::GRANT_ACTION];
    }

    public function contribute(Contributions $to): void
    {
        $state = new RequestState();
        $to->siteWarning(new PauseWarning(PauseCause::Errors, $state));
        $to->siteWarning(new PauseWarning(PauseCause::Manual, $state));
        $to->periodicStep($this->sender());
        $to->blockHost(new BlockHost(self::BODY_BLOCKS, MailPosts::POST_TYPE, hostOnly: self::OWN_BLOCKS));
    }

    public function boot(): void
    {
        $sender = $this->sender();
        add_action(MailQueue::SEND_HOOK, static function () use ($sender): void {
            Boundary::background('mail sender', static fn (): mixed => $sender->send());
        });
        add_action('init', [$this, 'register']);
        add_action('rest_api_init', [MailRoutes::class, 'register']);
        // The hourly budget as a WordPress setting over the sender's state row. [UNAPPROVED N1188]
        add_action('admin_init', [BudgetSetting::class, 'register']);
        $this->registerAbilities();
        if (defined('WP_CLI') && WP_CLI) {
            ActionMailCommand::register();
            MailCommand::register();
            ManualMailCommand::register();
            ManualMailBatchesCommand::register();
        }
    }

    /**
     * The Mail use cases as abilities, each the one entry a screen and the command line share. The two that read
     * WordPress data first make their use case per call (`prepared()`, the same call the PHP entries make).
     * Every one is site-level and full access's, except the manual send, which a grant carries. The labels and
     * descriptions are drafts for the lektor. [UNAPPROVED T510, T511, T512, T513, T514, T515, T516]
     */
    private function registerAbilities(): void
    {
        Abilities::register(
            new UpdateActionMail(),
            'Küldési pont beállítása',
            'Egy esemény valamelyik küldési pontjának be- vagy kikapcsolása és sablonjának kiválasztása.',
            UpdateActionMail::prepared(...)
        );
        Abilities::register(
            new SetEventMailLayout(),
            'Levélkeret mentése',
            'Egy esemény levélkeretének mentése.'
        );
        Abilities::register(
            new PauseMail(),
            'Levélküldés szüneteltetése',
            'A levélküldés leállítása; a levelek a küldési sorban gyűlnek.'
        );
        Abilities::register(
            new ResumeMail(),
            'Levélküldés folytatása',
            'A szüneteltetett levélküldés folytatása.'
        );
        Abilities::register(
            new SetMailBudget(),
            'Óránként küldhető levelek számának beállítása',
            'Annak beállítása, hogy óránként legfeljebb hány levelet küldhet a Kaláka.'
        );
        Abilities::register(
            new ClearMailQueue(),
            'Küldési sor ürítése',
            'Minden küldésre váró levél törlése a küldési sorból.'
        );
        Abilities::register(
            new ClearEventMail(),
            'Esemény küldésre váró leveleinek törlése',
            'Egy nem közzétett esemény küldésre váró leveleinek törlése.'
        );
        Abilities::register(
            new SendManualMail(),
            'Levél küldése jelentkezőknek',
            'Levél küldése egy levélsablonból a kiválasztott jelentkezőknek.',
            SendManualMail::prepared(...)
        );
    }

    /** @internal the `init` callback */
    public function register(): void
    {
        $this->registerPostType();
        $this->registerMeta();
        MailBlocks::register();

        // A mail's version is a revision ID, so revisions of mail documents are never pruned.
        add_filter('wp_' . MailPosts::POST_TYPE . '_revisions_to_keep', static fn (): int => -1);
        add_action('wp_after_insert_post', [new MailPosts(), 'ensureRevision'], 10, 2);
        add_filter('pre_trash_post', [BodyInUse::class, 'onPreTrash'], 10, 2);
        add_filter('pre_delete_post', [BodyInUse::class, 'onPreDelete'], 10, 2);
        add_filter('wp_insert_post_data', [BodyInUse::class, 'onInsertData'], 10, 2);
        add_filter('rest_pre_insert_' . MailPosts::POST_TYPE, [BodyInUse::class, 'onRestInsert'], 10, 2);
        add_filter('add_post_metadata', [BodyInUse::class, 'onMetaWrite'], 10, 4);
        add_filter('update_post_metadata', [BodyInUse::class, 'onMetaWrite'], 10, 4);
        add_filter('delete_post_metadata', [BodyInUse::class, 'onMetaDelete'], 10, 3);
        add_filter('wp_insert_post_data', [BodyCheck::class, 'onInsert'], 20, 2);
        add_filter('rest_pre_insert_' . MailPosts::POST_TYPE, [BodyCheck::class, 'onRestInsert'], 20, 2);
        add_action('admin_notices', [BodyCheck::class, 'notice']);
    }

    /**
     * Mail bodies are edited in the block editor (mail.md, "Content"; the event's layout is raw HTML, not a
     * post) by full access only, like forms: every capability, REST reads included (`MailRestController`),
     * needs `kalaka_full_access`. No admin menu item; its labels mirror `kalaka_form`'s.
     */
    private function registerPostType(): void
    {
        $full = AdminAccess::FULL_ACCESS;
        register_post_type(MailPosts::POST_TYPE, [
            'public' => false,
            // Owner 2026-11-16: a mail body is a "levélsablon"; the labels mirror kalaka_form's approved ones.
            'labels' => [
                'name' => 'Levélsablonok',
                'singular_name' => 'Levélsablon',
                'add_new' => 'Új levélsablon',
                'add_new_item' => 'Új levélsablon hozzáadása',
                'new_item' => 'Új levélsablon',
                'edit_item' => 'Levélsablon szerkesztése',
                'view_items' => 'Levélsablonok megtekintése',
                'all_items' => 'Összes levélsablon',
                'search_items' => 'Levélsablonok keresése',
                'not_found' => 'Nem található levélsablon.',
                'not_found_in_trash' => 'Nem található levélsablon a lomtárban.',
                'insert_into_item' => 'Beszúrás a levélsablonba',
                'uploaded_to_this_item' => 'Feltöltve ehhez a levélsablonhoz',
                'filter_items_list' => 'Levélsablonok listájának szűrése',
                'items_list_navigation' => 'Levélsablonok listájának navigációja',
                'items_list' => 'Levélsablonok listája',
                'item_published' => 'Levélsablon közzétéve.',
                'item_published_privately' => 'Levélsablon privátként közzétéve.',
                'item_reverted_to_draft' => 'Levélsablon visszaállítva vázlattá.',
                'item_trashed' => 'Levélsablon lomtárba helyezve.',
                'item_scheduled' => 'Levélsablon időzítve.',
                'item_updated' => 'Levélsablon frissítve.',
            ],
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
        // Whether the levélkeret wraps the template: the template's own flag, true until set.
        register_post_meta(MailPosts::POST_TYPE, MailPosts::META_USES_FRAME, [
            'type' => 'boolean',
            'single' => true,
            'default' => true,
            'show_in_rest' => true,
            'auth_callback' => static fn (bool $allowed, string $metaKey, int $postId, int $userId): bool
                => user_can($userId, AdminAccess::FULL_ACCESS),
        ]);
    }

    private function sender(): Sender
    {
        // The action and manual mails' composer: the sender runs (mail.md, "Sending").
        return $this->sender ??= new Sender(static fn (): Runner => Runner::current(), new KindComposer());
    }
}
