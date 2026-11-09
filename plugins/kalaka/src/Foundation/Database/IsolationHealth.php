<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Database;

use Kalaka\Foundation\HealthCheck;

/**
 * The database's health check: an admin notice and a Site Health test, both critical while
 * `BinlogCheck` refuses, that is while every use case is refused. It only reads `BinlogCheck`; it
 * writes nothing. foundation.md, "The use-case runner".
 */
final class IsolationHealth extends HealthCheck
{
    public const SITE_HEALTH_TEST = 'kalaka_database_isolation';

    private const CRITICAL_LABEL = 'Az adatbázis beállítása miatt a Kaláka nem tud adatot menteni!';

    private const CRITICAL_DESCRIPTION = 'A webtárhely adatbázisszerverén be van kapcsolva a bináris naplózás, és a '
        . 'binlog_format értéke STATEMENT. Ilyenkor a Kaláka minden mentést visszautasít. A tárhely üzemeltetőjének '
        . 'a binlog_format értékét MIXED-re vagy ROW-ra kell állítania.';

    private const GOOD_LABEL = 'Az adatbázis beállítása megfelel a Kalákának';

    private const GOOD_DESCRIPTION = 'A webtárhely adatbázisszervere engedi, hogy a Kaláka minden mentést '
        . 'READ COMMITTED elkülönítési szinten végezzen.';

    public function __construct(private readonly BinlogCheck $binlog)
    {
    }

    protected function testId(): string
    {
        return self::SITE_HEALTH_TEST;
    }

    protected function goodLabel(): string
    {
        return self::GOOD_LABEL;
    }

    /** @return array{critical: bool, label: string, description: string} the description is HTML */
    protected function check(): array
    {
        if ($this->binlog->refuses()) {
            return [
                'critical' => true,
                'label' => self::CRITICAL_LABEL,
                'description' => '<p>' . self::CRITICAL_DESCRIPTION . '</p>',
            ];
        }

        return [
            'critical' => false,
            'label' => self::GOOD_LABEL,
            'description' => '<p>' . self::GOOD_DESCRIPTION . '</p>',
        ];
    }
}
