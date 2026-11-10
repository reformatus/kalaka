<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Database;

use Kalaka\Foundation\Api\SiteWarning\Severity;
use Kalaka\Foundation\Api\SiteWarning\SiteHealth;
use Kalaka\Foundation\Api\SiteWarning\Shown;
use Kalaka\Foundation\Api\SiteWarning\SiteWarning;

/**
 * The database's site warning: a banner and a critical Site Health test while `BinlogCheck` refuses,
 * that is while every use case is refused. It only reads `BinlogCheck`; it writes nothing.
 * foundation.md, "The use-case runner" and "Site warnings".
 */
final class IsolationHealth implements SiteWarning
{
    public const SITE_HEALTH_TEST = 'kalaka_database_isolation';

    private const CRITICAL_LABEL = 'Az adatbázis beállítása miatt a Kaláka nem tud adatot menteni';

    private const CRITICAL_DESCRIPTION = 'A webtárhely adatbázisszerverén be van kapcsolva a bináris naplózás, és a '
        . 'binlog_format értéke STATEMENT. Ilyenkor a Kaláka minden mentést visszautasít. A tárhely üzemeltetőjének '
        . 'a binlog_format értékét MIXED-re vagy ROW-ra kell állítania.';

    private const GOOD_LABEL = 'Az adatbázis beállítása megfelel a Kalákának';

    private const GOOD_DESCRIPTION = 'A webtárhely adatbázisszervere engedi, hogy a Kaláka minden mentést '
        . 'READ COMMITTED elkülönítési szinten végezzen.';

    public function __construct(private readonly BinlogCheck $binlog)
    {
    }

    public function id(): string
    {
        return 'database_isolation';
    }

    public function check(): ?Shown
    {
        if (!$this->binlog->refuses()) {
            return null;
        }

        return new Shown(Severity::Error, self::CRITICAL_LABEL, self::CRITICAL_DESCRIPTION);
    }

    public function siteHealth(): SiteHealth
    {
        return new SiteHealth(self::SITE_HEALTH_TEST, self::GOOD_LABEL, self::GOOD_DESCRIPTION);
    }
}
