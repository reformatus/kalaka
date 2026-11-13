<?php

declare(strict_types=1);

namespace Kalaka\Admin;

use Kalaka\Admin\Api\Abilities;
use Kalaka\Admin\Cli\FullAccessCommand;
use Kalaka\Admin\GrantFullAccess;
use Kalaka\Admin\FullAccess\ProfileField;
use Kalaka\Admin\FullAccess\RevokeFullAccess;
use Kalaka\Admin\FullAccess\UsersColumn;
use Kalaka\Admin\Grant\DeleteGrant;
use Kalaka\Admin\Grant\SaveGrant;
use Kalaka\Admin\View\DeleteView;
use Kalaka\Admin\View\SaveView;
use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Schema;

final class Module extends AbstractModule
{
    /** The minimal WordPress role of outside grant holders: it can read and nothing more. */
    public const GRANTEE_ROLE = 'kalaka_grantee';

    /**
     * foundation.md, "Views and grants": a view of an event. `filter` and `columns` are JSON, validated
     * by `ViewShape` before they are written, in `longtext` for the same reason as the audit's JSON columns.
     */
    private const VIEWS = <<<'SQL'
CREATE TABLE {prefix}kalaka_views (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id bigint(20) unsigned NOT NULL,
  version int(10) unsigned NOT NULL,
  name varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  filter longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  columns longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  PRIMARY KEY  (id),
  KEY event_id (event_id,id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /**
     * foundation.md, "Views and grants": a user's grant on one event. `view_id` null names the event's
     * master view; `actions` is a JSON list of catalogue names, in `longtext` for the same reason.
     * `user_event` serves the site-wide reads by user (the abilities' gate, the events a user holds a
     * grant on); `event_view` serves the view edits' checks.
     */
    private const GRANTS = <<<'SQL'
CREATE TABLE {prefix}kalaka_grants (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id bigint(20) unsigned NOT NULL,
  version int(10) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  view_id bigint(20) unsigned DEFAULT NULL,
  bound_value varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  actions longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  PRIMARY KEY  (id),
  KEY event_user (event_id,user_id),
  KEY event_view (event_id,view_id),
  KEY user_event (user_id,event_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    public function id(): string
    {
        return 'admin';
    }

    public function requiresApi(): string
    {
        return '>=' . KALAKA_VERSION;
    }

    public function schema(): Schema
    {
        return new Schema(1, [self::VIEWS, self::GRANTS], [
            1 => self::addGranteeRole(...),
        ]);
    }

    /**
     * The per-event actions grants may carry (foundation.md, "Views and grants").
     * [UNAPPROVED S36-5]
     */
    public function capabilities(): array
    {
        return ['kalaka_notes', 'kalaka_audit_history'];
    }

    public function boot(): void
    {
        Abilities::registerCategory();
        // [UNAPPROVED S36-10] The labels of the trial's three abilities; [UNAPPROVED N7] the three new ones.
        Abilities::register(new SaveView(), 'Nézet mentése', 'Egy esemény nézetének létrehozása vagy módosítása.');
        Abilities::register(new DeleteView(), 'Nézet törlése', 'Egy esemény hozzáférés nélküli nézetének törlése.');
        Abilities::register(
            new SaveGrant(),
            'Hozzáférés mentése',
            'Hozzáférés megadása vagy módosítása egy esemény nézetéhez és műveleteihez.'
        );
        Abilities::register(new DeleteGrant(), 'Hozzáférés törlése', 'Egy hozzáférés visszavonása.');
        Abilities::register(
            new GrantFullAccess(),
            'Teljes hozzáférés megadása',
            'Teljes hozzáférés megadása egy felhasználónak a Kaláka minden eseményéhez.'
        );
        Abilities::register(
            new RevokeFullAccess(),
            'Teljes hozzáférés visszavonása',
            'Egy felhasználó teljes hozzáférésének visszavonása.'
        );

        add_action('show_user_profile', [ProfileField::class, 'render']);
        add_action('edit_user_profile', [ProfileField::class, 'render']);
        // Checked once core has checked the rest of the form, saved only once core has saved the user.
        add_action('user_profile_update_errors', [ProfileField::class, 'check'], PHP_INT_MAX, 3);
        add_action('profile_update', [ProfileField::class, 'save']);
        add_filter('manage_users_columns', [UsersColumn::class, 'columns']);
        add_filter('manage_users_custom_column', [UsersColumn::class, 'cell'], 10, 3);

        if (defined('WP_CLI') && WP_CLI) {
            FullAccessCommand::register();
        }
    }

    /**
     * Schema step 1: the minimal role of outside grant holders, which can read and nothing more. Safe to
     * run again. [UNAPPROVED S36-10] Its name.
     */
    private static function addGranteeRole(): void
    {
        add_role(self::GRANTEE_ROLE, 'Kaláka-felhasználó', ['read' => true]);
    }
}
