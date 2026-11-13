<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Grant\GrantQuery;
use Kalaka\Tests\Integration\Foundation\PerEventCostTestCase;

/** Every statement of the grant query stays inside its event and user. */
final class GrantQueryCostTest extends PerEventCostTestCase
{
    private const USERS = [1, 2, 3];

    /** @var array<int, int> the first view's id by event */
    private array $firstViews = [];

    private function seedEvent(int $event): void
    {
        // phpcs:disable WordPress.DB.PreparedSQL -- test setup; each row is prepared, the table names are the prefix and constants
        global $wpdb;

        $views = [];
        foreach (self::USERS as $ignored) {
            $views[] = $wpdb->prepare(
                "(%d, 1, 'Nézet', '[{\"column\":\"status\",\"in\":[\"registered\"]}]',"
                . " '[{\"column\":\"code\",\"write\":false}]')",
                $event
            );
        }
        $wpdb->query(
            "INSERT INTO {$wpdb->prefix}kalaka_views (event_id, version, name, filter, columns) VALUES "
            . implode(', ', $views)
        );
        $firstView = (int) $wpdb->insert_id;
        $this->firstViews[$event] = $firstView;

        $grants = [];
        foreach (self::USERS as $index => $user) {
            $grants[] = $wpdb->prepare(
                '(%d, 1, %d, %d, NULL, %s)',
                $event,
                $user,
                $firstView + $index,
                '["kalaka_edit_fields"]'
            );
            $grants[] = $wpdb->prepare('(%d, 1, %d, NULL, NULL, %s)', $event, $user, '[]');
        }
        $wpdb->query(
            "INSERT INTO {$wpdb->prefix}kalaka_grants (event_id, version, user_id, view_id, bound_value, actions)"
            . ' VALUES '
            . implode(', ', $grants)
        );
        // phpcs:enable WordPress.DB.PreparedSQL
    }

    public function testTheUsersGrantsStayInsideTheirEvent(): void
    {
        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            static fn (int $event): array => (new GrantQuery())->forUser($event, 2)
        );
    }

    public function testTheChecksOfAViewStayInsideTheirEvent(): void
    {
        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            fn (int $event): array => [
                (new GrantQuery())->boundToView($event, $this->firstViews[$event]),
                (new GrantQuery())->usesView($event, $this->firstViews[$event]),
            ]
        );
    }

    public function testTheGrantsOfAnEventStayInsideIt(): void
    {
        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            static fn (int $event): array => (new GrantQuery())->ofEvent($event)
        );
    }
}
