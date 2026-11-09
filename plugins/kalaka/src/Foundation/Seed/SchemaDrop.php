<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Seed;

use Kalaka\Foundation\Api\Error\KalakaException;
use Kalaka\Foundation\Api\Module\Module;
use Kalaka\Foundation\Boot;
use Kalaka\Foundation\Database\Statements;
use Kalaka\Foundation\SchemaUpgrader;

/**
 * The development drop: drops the tables each module declares in its `Schema` and its
 * `kalaka_schema_<id>` option, clears the boot's `kalaka_load_failure` record, then recreates every
 * schema through the upgrader. It never drops a table no module declares and touches no other
 * WordPress data. modules-and-extensions.md, "Schema ownership".
 */
final class SchemaDrop
{
    /** @param list<Module> $modules the modules that passed the version check, in boot order */
    public function __construct(
        private readonly array $modules,
        private readonly SchemaUpgrader $upgrader = new SchemaUpgrader(),
    ) {
    }

    /** @throws SeedFailure when a table name cannot be read, a table cannot be dropped or the schema cannot be recreated */
    public function run(): void
    {
        $statements = new Statements();

        $tables = [];
        foreach ($this->modules as $module) {
            foreach ($module->schema()->tables as $statement) {
                if (preg_match('/^\s*CREATE\s+TABLE\s+\{prefix\}([A-Za-z0-9_]+)/i', $statement, $match) !== 1) {
                    $error = new SeedFailure('Schema of ' . $module->id() . ': cannot read a table name.');
                    throw $error;
                }
                $tables[] = $statements->prefixed($match[1]);
            }
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        foreach ($tables as $table) {
            try {
                $statements->dropTableIfExists($table);
            } catch (KalakaException $e) {
                // DatabaseError keeps MariaDB's message in `previous`; the command prints that.
                $error = new SeedFailure('Dropping ' . $table . ': ' . ($e->getPrevious() ?? $e)->getMessage(), $e);
                throw $error;
            }
        }
        foreach ($this->modules as $module) {
            delete_option($this->upgrader->versionOption($module));
            \WP_Upgrader::release_lock($this->upgrader->lockName($module));
        }
        delete_option(Boot::FAILURE_OPTION);

        foreach ($this->modules as $module) {
            try {
                $this->upgrader->upgrade($module);
            } catch (\Throwable $e) {
                $error = new SeedFailure('Schema of ' . $module->id() . ': ' . $e->getMessage(), $e);
                throw $error;
            }
        }
    }
}
