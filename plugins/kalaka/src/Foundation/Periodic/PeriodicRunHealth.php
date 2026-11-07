<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Periodic;

use Kalaka\Foundation\Api\Clock;
use Kalaka\Foundation\Database\BinlogCheck;
use Kalaka\Foundation\HealthCheck;

/**
 * The periodic run's health check: an admin notice and a Site Health test, both late while the last
 * completed run is older than 20 minutes or there has been none. It only reads the last-run option,
 * the `Clock` and `BinlogCheck`; it writes nothing and never schedules, starts or unlocks a run.
 * foundation.md, "Deferred work".
 */
final class PeriodicRunHealth extends HealthCheck
{
    /** Longer than `PeriodicRun::LOCK_TTL` (900), so a lock left by a crash is taken over before the warning shows. */
    private const MAX_AGE_SECONDS = 1200;

    public const SITE_HEALTH_TEST = 'kalaka_periodic_run';

    private const GOOD_LABEL = 'Az időzített események feldolgozása rendben van';

    private const LATE_LABEL = 'Az időzített események feldolgozása több mint 20 perce megakadt!';

    private const NEVER_LABEL = 'Az időzített események feldolgozása nem működik!';

    private const LAST_RUN = 'Az utolsó befejezett futtatás: %s.';

    private const CONSEQUENCE = 'Amíg a futtatás áll, nem mennek ki a levelek, és késnek az időhöz kötött teendők.';

    private const CRON_CAUSE = 'A webtárhely ütemezőjének percenként meg kell hívnia a wp-cron.php-t.';

    /** While `BinlogCheck` refuses, the run does not run at all, so WP-Cron is not the cause. */
    private const BINLOG_CAUSE = 'A futtatás áll, mert a webtárhely adatbázisszerverén be van kapcsolva a bináris '
        . 'naplózás binlog_format=STATEMENT értékkel, és ilyenkor a Kaláka nem ment adatot. A tárhely '
        . 'üzemeltetőjének a binlog_format értékét MIXED-re vagy ROW-ra kell állítania.';

    public function __construct(
        private readonly Clock $clock,
        private readonly BinlogCheck $binlog = new BinlogCheck(),
    ) {
    }

    protected function testId(): string
    {
        return self::SITE_HEALTH_TEST;
    }

    protected function goodLabel(): string
    {
        return self::GOOD_LABEL;
    }

    /** @return array{critical: bool, label: string, description: string} critical while late; HTML description */
    protected function check(): array
    {
        $stored = get_option(PeriodicRun::LAST_RUN_OPTION);
        $lastRun = is_string($stored) ? PeriodicRun::parseLastRun($stored) : null;

        if ($lastRun === null) {
            return [
                'critical' => true,
                'label' => self::NEVER_LABEL,
                'description' => '<p>' . $this->consequence() . '</p>',
            ];
        }

        $lastRunText = sprintf(self::LAST_RUN, esc_html((string) wp_date('Y. m. d. H:i', $lastRun->getTimestamp())));

        if ($this->clock->now() <= $lastRun->modify('+' . self::MAX_AGE_SECONDS . ' seconds')) {
            return ['critical' => false, 'label' => self::GOOD_LABEL, 'description' => '<p>' . $lastRunText . '</p>'];
        }

        return [
            'critical' => true,
            'label' => self::LATE_LABEL,
            'description' => '<p>' . $lastRunText . ' ' . $this->consequence() . '</p>',
        ];
    }

    private function consequence(): string
    {
        return self::CONSEQUENCE . ' ' . ($this->binlog->refuses() ? self::BINLOG_CAUSE : self::CRON_CAUSE);
    }
}
