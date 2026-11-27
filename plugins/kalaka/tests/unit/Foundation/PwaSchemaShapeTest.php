<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Foundation;

use Kalaka\Events\Module as EventsModule;
use Kalaka\Portal\Module as PortalModule;
use PHPUnit\Framework\TestCase;

/** The parked PWA scope's shape: the event's status-page slot and the push subscription table. */
final class PwaSchemaShapeTest extends TestCase
{
    public function testTheEventHasOneNullableStatusPagePostId(): void
    {
        $schema = (new EventsModule())->schema();

        self::assertSame(5, $schema->version);
        self::assertCount(1, $schema->tables);
        self::assertSame(1, preg_match_all('/^\s+status_page_post_id\s/m', $schema->tables[0]));
        self::assertStringContainsString(
            "\n  status_page_post_id bigint(20) unsigned DEFAULT NULL,\n",
            $schema->tables[0]
        );
    }

    public function testTheSubscriptionTableHasTheDeclaredColumnsAndKeys(): void
    {
        $schema = (new PortalModule())->schema();
        self::assertSame(1, $schema->version);
        self::assertCount(1, $schema->tables);
        $sql = $schema->tables[0];

        self::assertStringContainsString('CREATE TABLE {prefix}kalaka_push_subscriptions (', $sql);
        self::assertSame(
            [
                'event_id' => 'bigint(20) unsigned',
                'code' => 'varchar(16)',
                'endpoint_hash' => 'varchar(64)',
                'endpoint' => 'longtext',
                'key_p256dh' => 'varchar(128)',
                'key_auth' => 'varchar(32)',
                'created_at' => 'datetime(6)',
            ],
            self::columns($sql)
        );

        // Compared exactly: the URL, its hash and the keys; the registration code sorts like everywhere else.
        foreach (['endpoint_hash', 'endpoint', 'key_p256dh', 'key_auth'] as $column) {
            self::assertMatchesRegularExpression(
                '/^\s+' . $column . ' \S+ CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,$/m',
                $sql,
                $column
            );
        }

        // The URL is too long to index: the unique key is the hash, and no key names `endpoint` itself.
        self::assertStringContainsString('PRIMARY KEY  (event_id,code,endpoint_hash)', $sql);
        self::assertStringContainsString('KEY endpoint_hash (endpoint_hash)', $sql);
        self::assertSame(0, preg_match('/KEY[^(]*\([^)]*\bendpoint\b[^)]*\)/', $sql));
        // No last-seen column: a dead subscription is found from the push service's rejection.
        self::assertStringNotContainsString('last_seen', $sql);
    }

    /** @return array<string, string> column => type, from the declared lines only */
    private static function columns(string $sql): array
    {
        preg_match_all('/^  (\w+) (\w+(?:\(\d+(?:,\d+)?\))?(?: unsigned)?) /m', $sql, $found, PREG_SET_ORDER);
        $columns = [];
        foreach ($found as $match) {
            if (!in_array($match[1], ['PRIMARY', 'UNIQUE', 'KEY'], true)) {
                $columns[$match[1]] = $match[2];
            }
        }

        return $columns;
    }
}
