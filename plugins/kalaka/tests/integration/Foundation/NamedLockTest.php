<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Database\NamedLock;
use WP_UnitTestCase;

/** A named lock: one holder at a time across connections, scoped to the site, freed on release. */
final class NamedLockTest extends WP_UnitTestCase
{
    private function holdOnAnotherConnection(string $name): \mysqli
    {
        $connection = new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        $result = $connection->query("SELECT GET_LOCK('" . $connection->real_escape_string($name) . "', 0)");
        self::assertInstanceOf(\mysqli_result::class, $result);
        self::assertSame('1', (string) ($result->fetch_row()[0] ?? ''));

        return $connection;
    }

    public function testTheNameIsScopedToTheSiteAndWithinMariaDbsLimit(): void
    {
        global $wpdb;
        $scoped = (new NamedLock('teszt'))->scoped();

        self::assertSame(
            'kalaka_' . substr(md5(DB_NAME . '|' . $wpdb->prefix), 0, 12) . '_teszt',
            $scoped
        );
        self::assertLessThanOrEqual(64, strlen((new NamedLock(str_repeat('a', 40)))->scoped()));
    }

    public function testAnotherConnectionsLockIsNotTakenAndAFreedOneIs(): void
    {
        $lock = new NamedLock('teszt');
        $other = $this->holdOnAnotherConnection($lock->scoped());
        try {
            self::assertFalse($lock->acquire());
        } finally {
            $other->close();
        }

        self::assertTrue($lock->acquire());
        $lock->release();
        $again = $this->holdOnAnotherConnection($lock->scoped());
        $again->close();
    }

    public function testABadNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NamedLock('Mail-Sender');
    }
}
