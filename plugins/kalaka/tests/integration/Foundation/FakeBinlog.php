<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

/**
 * Answers `BinlogCheck`'s read in place of the server, which tests cannot switch to binary logging:
 * a `query` filter replaces that one statement, and counts how often it was sent. The harness restores
 * the hooks after each test; `remove()` ends it earlier. Give the code under test a new `BinlogCheck`,
 * which has nothing cached yet.
 */
final class FakeBinlog
{
    public const READ = 'SELECT @@GLOBAL.log_bin, @@SESSION.binlog_format';

    public int $reads = 0;

    private readonly \Closure $filter;

    private function __construct(string $replacement)
    {
        $this->filter = function (string $query) use ($replacement): string {
            if ($query !== self::READ) {
                return $query;
            }
            $this->reads++;

            return $replacement;
        };
        add_filter('query', $this->filter);
    }

    /** Binary logging is on with `binlog_format=STATEMENT`: writes under READ COMMITTED would fail. */
    public static function statementFormat(): self
    {
        return self::answering('1', 'STATEMENT');
    }

    public static function answering(string $logBin, string $format): self
    {
        global $wpdb;

        return new self((string) $wpdb->prepare('SELECT %s, %s', $logBin, $format));
    }

    /** The read fails: an unknown system variable (error 1193). */
    public static function failing(): self
    {
        return new self('SELECT @@GLOBAL.kalaka_no_such_variable, @@SESSION.binlog_format');
    }

    /** The read returns no row. */
    public static function empty(): self
    {
        return new self("SELECT 1, 'STATEMENT' FROM DUAL WHERE 0");
    }

    public function remove(): void
    {
        remove_filter('query', $this->filter);
    }
}
