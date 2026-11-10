<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Periodic;

use Kalaka\Foundation\Api\Clock;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\SiteWarning\Severity;
use Kalaka\Foundation\Api\SiteWarning\SiteHealth;
use Kalaka\Foundation\Api\SiteWarning\Shown;
use Kalaka\Foundation\Api\SiteWarning\SiteWarning;
use Kalaka\Foundation\Database\BinlogCheck;

/**
 * The periodic run's stall warning: a banner and a critical Site Health test while the last completed
 * run is older than 20 minutes or there has been none. It names its cause: the run itself, when it
 * failed after the last completed run (its failure in the details), otherwise cron. Silent while the
 * load failed or `BinlogCheck` refuses: those conditions have their own warnings. It only reads the
 * last-run time, the run's last recorded failure, the `Clock`, the load state and `BinlogCheck`; it
 * writes nothing and never schedules, starts or unlocks a run. foundation.md, "Deferred work".
 */
final class PeriodicRunHealth implements SiteWarning
{
    /** Longer than `PeriodicRun::LOCK_TTL` (900), so a lock left by a crash is taken over before the warning shows. */
    private const MAX_AGE_SECONDS = 1200;

    public const SITE_HEALTH_TEST = 'kalaka_periodic_run';

    private const GOOD_LABEL = 'Az időzített események feldolgozása rendben van';

    private const LATE_LABEL = 'Az időzített események feldolgozása több mint 20 perce megakadt';

    private const NEVER_LABEL = 'Az időzített események feldolgozása nem működik';

    private const LAST_RUN = 'Az utolsó befejezett futtatás: %s.';

    private const CONSEQUENCE = 'Amíg a futtatás áll, nem mennek ki a levelek, és késnek az időhöz kötött teendők.';

    private const CRON_CAUSE = 'A webtárhely ütemezőjének percenként meg kell hívnia a wp-cron.php-t.';

    private const FAILING_CAUSE = 'A futtatás elindul, de hiba miatt nem fejeződik be.';

    public function __construct(
        private readonly Clock $clock,
        private readonly BinlogCheck $binlog = new BinlogCheck(),
    ) {
    }

    public function id(): string
    {
        return 'periodic_run';
    }

    public function check(): ?Shown
    {
        $lastRun = self::lastRun();
        if ($lastRun !== null && $this->clock->now() <= $lastRun->modify('+' . self::MAX_AGE_SECONDS . ' seconds')) {
            return null;
        }
        if (!LoadState::current()->isOpen() || $this->binlog->refuses()) {
            return null;
        }

        $failure = PeriodicRun::lastFailure();
        $failing = $failure !== null && ($lastRun === null || $failure['at'] > $lastRun);
        $description = self::CONSEQUENCE . ' ' . ($failing ? self::FAILING_CAUSE : self::CRON_CAUSE);
        if ($lastRun !== null) {
            $description = self::lastRunText($lastRun) . ' ' . $description;
        }

        return new Shown(
            Severity::Error,
            $lastRun === null ? self::NEVER_LABEL : self::LATE_LABEL,
            $description,
            $failing ? $failure['error'] : null
        );
    }

    /**
     * No Site Health test while the warning is silent for another warning's cause (the load failed,
     * or the database refuses writes): that warning explains it. Otherwise the test, with the last
     * completed run, if there was one, while all is well.
     */
    public function siteHealth(): ?SiteHealth
    {
        if (!LoadState::current()->isOpen() || $this->binlog->refuses()) {
            return null;
        }
        $lastRun = self::lastRun();

        return new SiteHealth(
            self::SITE_HEALTH_TEST,
            self::GOOD_LABEL,
            $lastRun === null ? '' : self::lastRunText($lastRun)
        );
    }

    private static function lastRun(): ?\DateTimeImmutable
    {
        $stored = get_option(PeriodicRun::LAST_RUN_OPTION);

        return is_string($stored) ? PeriodicRun::parseLastRun($stored) : null;
    }

    private static function lastRunText(\DateTimeImmutable $lastRun): string
    {
        return sprintf(self::LAST_RUN, (string) wp_date('Y. m. d. H:i', $lastRun->getTimestamp()));
    }
}
