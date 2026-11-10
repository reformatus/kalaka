<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Database;

use Kalaka\Foundation\Api\Error\KalakaException;

/**
 * Would writes under READ COMMITTED fail? Yes while the server logs binary with
 * `binlog_format=STATEMENT` (MariaDB refuses them with error 1665). One read per instance, cached:
 * Foundation's module shares one instance between the runner and the periodic run, and the boot's
 * site warnings share another, so a request reads at most twice, and only when it runs a use case
 * or checks the warnings. A read that fails or returns nothing
 * counts as "writes work": error 1665 still catches a real problem at the first write.
 * foundation.md, "The use-case runner".
 */
final class BinlogCheck
{
    /** The context of the refusal and of error 1665. */
    public const REFUSAL = 'A webtárhely adatbázis-beállítása (binlog_format=STATEMENT) miatt a Kaláka most nem tud '
        . 'adatot menteni. Szólj a webhely üzemeltetőjének.';

    private ?bool $refuses = null;

    private readonly Statements $statements;

    public function __construct()
    {
        $this->statements = new Statements();
    }

    /** True: binary logging is on with `binlog_format=STATEMENT`, so every write would fail. */
    public function refuses(): bool
    {
        return $this->refuses ??= $this->read();
    }

    private function read(): bool
    {
        try {
            $rows = $this->statements->select('SELECT @@GLOBAL.log_bin, @@SESSION.binlog_format');
        } catch (KalakaException) {
            return false;
        }
        if ($rows === []) {
            return false;
        }

        [$logBin, $format] = array_values($rows[0]) + [null, null];

        return in_array(strtoupper((string) $logBin), ['1', 'ON'], true)
            && strtoupper((string) $format) === 'STATEMENT';
    }
}
