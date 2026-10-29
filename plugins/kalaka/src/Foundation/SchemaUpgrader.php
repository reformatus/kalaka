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

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $lock = 'kalaka_schema_' . $id;
        if (!\WP_Upgrader::create_lock($lock, self::LOCK_TTL)) {
            throw new \RuntimeException('Schema upgrade in progress.');
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
            \WP_Upgrader::release_lock($lock);
        }
    }
}
