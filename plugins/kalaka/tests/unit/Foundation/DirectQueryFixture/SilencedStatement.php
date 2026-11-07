<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Foundation\DirectQueryFixture;

/** A file that silences the direct-query rule: the exemptions check must find it. */
final class SilencedStatement
{
    public function run(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the fixture the exemptions check must find
        $wpdb->query('SELECT 1');
    }
}
