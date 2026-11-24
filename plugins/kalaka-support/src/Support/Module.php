<?php

declare(strict_types=1);

namespace Kalaka\Support;

use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Module\Schema;
use Kalaka\Support\Directory\Cli\DirectoryCommand;
use Kalaka\Support\Directory\NodeList;
use Kalaka\Support\Directory\Rest\NodesController;
use Kalaka\Support\Offers\OfferClaims;
use Kalaka\Support\Offers\OfferRequests;
use Kalaka\Support\Offers\SupportComponent;
use Kalaka\Support\Picker\CongregationFieldType;
use Kalaka\Support\Share\ShareComponent;
use Kalaka\Support\Share\SharePayerKind;

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

    /**
     * money.md, "Sponsor and church support": an event's support offers, one row per offer, keyed by (event ID,
     * offer key); `position` is the order that caps them. The row is the offer's seat pool, locked `FOR UPDATE`
     * at the quota level. `node_id` is the granting directory node. The amounts are read as they are now: there
     * is no tariff snapshot. [UNAPPROVED N93]
     */
    private const OFFERS = <<<'SQL'
CREATE TABLE {prefix}kalaka_support_offers (
  event_id bigint(20) unsigned NOT NULL,
  offer_key varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  position int(10) unsigned NOT NULL,
  label varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  node_id bigint(20) unsigned NOT NULL,
  amount decimal(26,8) NOT NULL,
  currency char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  needs_request tinyint(1) unsigned NOT NULL,
  seat_limit int(10) unsigned DEFAULT NULL,
  version int(10) unsigned NOT NULL,
  PRIMARY KEY  (event_id,offer_key)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /**
     * money.md, "Congregation share": an event's share is on when it has a row here, keyed by the event. There is
     * nothing else to store: the mode and the amount are the registrant's answers. [UNAPPROVED N141]
     */
    private const SHARE = <<<'SQL'
CREATE TABLE {prefix}kalaka_support_share (
  event_id bigint(20) unsigned NOT NULL,
  version int(10) unsigned NOT NULL,
  PRIMARY KEY  (event_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /** Constructing hooks nothing (extensions are collected again by the Playground smoke test). */
    public function __construct(
        private readonly CongregationFieldType $congregationField = new CongregationFieldType()
    ) {
    }

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
        return new Schema(3, [self::NODES, self::OFFERS, self::SHARE]);
    }

    public function contribute(Contributions $to): void
    {
        $to->fieldType($this->congregationField);
        $to->feeComponent(new SupportComponent());
        $to->feeComponent(new ShareComponent());
        $to->payerKindSource(new SharePayerKind());
        $to->requestSource(new OfferRequests());
        $to->seatClaimSource(new OfferClaims());
    }

    public function boot(): void
    {
        add_action('rest_api_init', static function (): void {
            (new NodesController(new NodeList()))->register();
        });
        add_action('init', [$this, 'register']);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('kalaka-support', DirectoryCommand::class);
        }
    }

    /**
     * Registers the congregation field's block from `build/`, where the JavaScript build writes it
     * (engineering.md, "Builds"). Without a build it is not registered, and a published form holding it
     * closes the site.
     *
     * @internal the `init` action
     */
    public function register(): void
    {
        $block = dirname(__DIR__, 2) . '/build/Support/Picker/Blocks/congregation-field';
        if (is_file($block . '/block.json')) {
            register_block_type($block);
        }
    }
}
