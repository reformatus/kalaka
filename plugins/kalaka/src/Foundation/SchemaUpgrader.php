<?php

declare(strict_types=1);

namespace Kalaka\Foundation;

final class SchemaUpgrader
{
    private const LOCK_TTL = 600;

    /** The option that holds the schema version the module is at. */
    public function versionOption(Api\Module\Module $module): string
    {
        return 'kalaka_schema_' . $module->id();
    }

    /** The name of the lock held while the module's schema is upgraded. */
    public function lockName(Api\Module\Module $module): string
    {
        return 'kalaka_schema_' . $module->id();
    }

    public function upgrade(Api\Module\Module $module): void
    {
        global $wpdb;

        $schema = $module->schema();
        $option = $this->versionOption($module);
        if ($schema->version <= (int) get_option($option, 0)) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $lock = $this->lockName($module);
        if (!\WP_Upgrader::create_lock($lock, self::LOCK_TTL)) {
            throw new UpgradeInProgress('Schema upgrade in progress.');
        }

        try {
            // Another request may have finished the upgrade while we waited for the lock.
            wp_cache_delete($option, 'options');
            wp_cache_delete('alloptions', 'options');
            $stored = (int) get_option($option, 0);
            if ($schema->version <= $stored) {
                return;
            }

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            foreach ($schema->tables as $table) {
                $sql = str_replace('{prefix}', $wpdb->prefix, $table);
                dbDelta($sql);
                // dbDelta() reports no errors; a dry run that still finds changes means it failed.
                $remaining = dbDelta($sql, false);
                if ($remaining !== []) {
                    throw new \RuntimeException('Schema not applied: ' . implode(' ', $remaining));
                }
            }

            $keys = array_keys($schema->steps);
            sort($keys);
            foreach ($keys as $key) {
                if ($key > $stored && $key <= $schema->version) {
                    ($schema->steps[$key])();
                    update_option($option, $key, true);
                }
            }

            update_option($option, $schema->version, true);
        } finally {
            \WP_Upgrader::release_lock($lock);
        }
    }
}
