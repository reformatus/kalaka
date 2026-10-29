<?php

declare(strict_types=1);

namespace Kalaka\Foundation;

final class SchemaUpgrader
{
    private const LOCK_TTL = 600;

    public function upgrade(Api\Module $module): void
    {
        global $wpdb;

        $id = $module->id();
        $schema = $module->schema();
        $option = 'kalaka_schema_' . $id;
        $stored = (int) get_option($option, 0);
        if ($schema->version <= $stored) {
            return;
        }

        $lock = 'kalaka_schema_lock_' . $id;
        if (!add_option($lock, time(), '', false)) {
            if (time() - (int) get_option($lock) <= self::LOCK_TTL) {
                throw new \RuntimeException('Schema upgrade in progress.');
            }
            delete_option($lock);
            if (!add_option($lock, time(), '', false)) {
                throw new \RuntimeException('Schema upgrade in progress.');
            }
        }

        try {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            foreach ($schema->tables as $table) {
                dbDelta(str_replace('{prefix}', $wpdb->prefix, $table));
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
            delete_option($lock);
        }
    }
}
