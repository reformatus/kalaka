<?php

declare(strict_types=1);

namespace Kalaka\Admin;

use Kalaka\Admin\Api\Abilities;
use Kalaka\Admin\Cli\FullAccessCommand;
use Kalaka\Admin\FullAccess\GrantFullAccess;
use Kalaka\Admin\FullAccess\ProfileField;
use Kalaka\Admin\FullAccess\RevokeFullAccess;
use Kalaka\Admin\FullAccess\UsersColumn;
use Kalaka\Admin\Grant\DeleteGrant;
use Kalaka\Admin\Grant\SaveGrant;
use Kalaka\Admin\Grid\GridPage;
use Kalaka\Admin\Grid\Texts as GridTexts;
use Kalaka\Admin\Rest\GridRoutes;
use Kalaka\Admin\View\DeleteView;
use Kalaka\Admin\View\SaveMasterCounts;
use Kalaka\Admin\View\SaveView;
use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Schema;

final class Module extends AbstractModule
{
    /** The minimal WordPress role of outside grant holders: it can read and nothing more. */
    public const GRANTEE_ROLE = 'kalaka_grantee';

    /**
     * foundation.md, "Views and grants": a view of an event. `filter`, `columns` and `counts` (the members
     * the grid's sum row counts per column, S37; null on a row written before it) are JSON, validated by
     * `ViewShape` before they are written, in `longtext` for the same reason as the audit's JSON columns.
     * A row with `master` 1 is the event's master view (S37): it holds only the master grid's `counts`; its
     * name, filter and columns are stored empty and never read. `master_event` is the event of a master row
     * and null on every other row (`ViewRepository` writes it with `master`), so its unique key allows at
     * most one master row per event. It is a plain column, not a generated one: Playground's SQLite driver
     * rejects generated columns, and the site must still load there.
     */
    private const VIEWS = <<<'SQL'
CREATE TABLE {prefix}kalaka_views (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id bigint(20) unsigned NOT NULL,
  version int(10) unsigned NOT NULL,
  name varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  filter longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  columns longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  counts longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci,
  master tinyint(1) NOT NULL DEFAULT 0,
  master_event bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY master_event (master_event),
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
        return new Schema(2, [self::VIEWS, self::GRANTS], [
            1 => self::addGranteeRole(...),
        ]);
    }

    /**
     * The per-event actions grants may carry (foundation.md, "Views and grants").
     */
    public function capabilities(): array
    {
        return ['kalaka_notes', 'kalaka_audit_history'];
    }

    public function boot(): void
    {
        Abilities::registerCategory();
        // The labels of the abilities.
        Abilities::register(
            new SaveView(),
            'Nézet mentése',
            'Egy esemény nézetének létrehozása vagy módosítása.',
            SaveView::prepared(...)
        );
        Abilities::register(new DeleteView(), 'Nézet törlése', 'Egy esemény nézetének törlése.');
        Abilities::register(
            new SaveMasterCounts(),
            GridTexts::MASTER_COUNTS_LABEL,
            GridTexts::MASTER_COUNTS_DESCRIPTION,
            SaveMasterCounts::prepared(...)
        );
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

        // The per-event „Jelentkezők” grid (S37): its menu, its app and its REST routes.
        add_action('admin_menu', [GridPage::class, 'menu']);
        add_action('admin_enqueue_scripts', [GridPage::class, 'enqueue']);
        add_action('rest_api_init', [GridRoutes::class, 'register']);
        // WooCommerce sends users who cannot edit posts from wp-admin to "My account"; a grant holder (the
        // minimal role) must still reach the grid.
        add_filter('woocommerce_prevent_admin_access', [GridPage::class, 'allowAdmin']);

        if (defined('WP_CLI') && WP_CLI) {
            FullAccessCommand::register();
        }
    }

    /**
     * Schema step 1: the minimal role of outside grant holders, which can read and nothing more. Safe to
     * run again.
     */
    private static function addGranteeRole(): void
    {
        add_role(self::GRANTEE_ROLE, 'Kaláka-felhasználó', ['read' => true]);
    }
}
