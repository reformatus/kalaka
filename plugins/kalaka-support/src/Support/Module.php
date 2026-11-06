<?php

declare(strict_types=1);

namespace Kalaka\Support;

use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Schema;
use Kalaka\Support\Directory\Cli\DirectoryCommand;
use Kalaka\Support\Directory\NodeList;
use Kalaka\Support\Directory\Rest\NodesController;

final class Module extends AbstractModule
{
    /**
     * money.md, "Sponsor and church support": the organization directory. Only `parent_id` is
     * stored about the tree; ancestors and descendants are computed when read.
     */
    private const NODES = <<<'SQL'
CREATE TABLE {prefix}kalaka_support_nodes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  external_id varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  parent_id bigint(20) unsigned DEFAULT NULL,
  type varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  name varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  aliases text CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  active tinyint(1) unsigned NOT NULL,
  version int(10) unsigned NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY external_id (external_id),
  KEY parent_id (parent_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    public function id(): string
    {
        return 'support';
    }

    /** During 0.x a minor bump of VERSION updates this range in the same change. */
    public function requiresApi(): string
    {
        return '>=0.1 <0.2';
    }

    public function schema(): Schema
    {
        return new Schema(1, [self::NODES]);
    }

    public function boot(): void
    {
        add_action('rest_api_init', static function (): void {
            (new NodesController(new NodeList()))->register();
        });

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('kalaka-support', DirectoryCommand::class);
        }
    }
}
