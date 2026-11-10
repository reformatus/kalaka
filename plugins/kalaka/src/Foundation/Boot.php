<?php

declare(strict_types=1);

namespace Kalaka\Foundation;

use Kalaka\Foundation\Api\LoadFailure;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Clock\WordPressClock;
use Kalaka\Foundation\Database\BinlogCheck;
use Kalaka\Foundation\Database\IsolationHealth;
use Kalaka\Foundation\Periodic\PeriodicRunHealth;
use Kalaka\Foundation\SiteWarning\ErrorLogWarning;
use Kalaka\Foundation\SiteWarning\LoadFailureWarning;
use Kalaka\Foundation\SiteWarning\SiteWarnings;

/**
 * Any load failure closes the site for this request (see LoadState):
 * modules-and-extensions.md, "Failure isolation".
 */
final class Boot
{
    /** The WP option holding the fingerprint of the load failures last logged. */
    public const FAILURE_OPTION = 'kalaka_load_failure';

    /** @var list<LoadFailure> */
    private array $failures = [];

    /**
     * What each failure is logged with: the exception, if one was caught; none for a schema
     * upgrade another request is running, which closes this request but is not logged.
     *
     * @var list<array{LoadFailure, ?\Throwable}>
     */
    private array $logged = [];

    /** @param list<Api\Module\Module> $coreModules in boot order */
    public function __construct(private readonly array $coreModules)
    {
    }

    public function run(): void
    {
        // Before any module, so the failed-load warning shows even when Foundation's module did not load.
        $warnings = self::siteWarnings();
        $warnings->register();

        $set = new ModuleSet();
        foreach ($this->coreModules as $module) {
            $set->add($module);
        }

        try {
            do_action('kalaka_register_modules', $set);
        } catch (\Throwable $e) {
            $this->fail('kalaka_register_modules', $e->getMessage(), $e);
        }

        $modules = [];
        foreach ($set->all() as $module) {
            if ($this->isCompatible($module)) {
                $modules[] = $module;
            }
        }

        $upgrader = new SchemaUpgrader();
        $upgraded = [];
        foreach ($modules as $module) {
            try {
                $upgrader->upgrade($module);
                $upgraded[] = $module;
            } catch (\Throwable $e) {
                $this->fail($module->id(), $e->getMessage(), $e);
            }
        }

        $contributions = new Api\Module\Contributions();
        foreach ($upgraded as $module) {
            try {
                $module->contribute($contributions);
                $module->boot();
            } catch (\Throwable $e) {
                $this->fail($module->id(), $e->getMessage(), $e);
            }
        }

        foreach ($contributions->siteWarnings() as $warning) {
            $warnings->add($warning);
        }

        foreach ($this->coreModules as $module) {
            if ($module instanceof Module) {
                $module->collect($modules, $contributions);
            }
        }

        foreach ($set->failures() as $failure) {
            $this->fail($failure->moduleId, $failure->reason);
        }

        $this->logOnChange();
        LoadState::record($this->failures);
    }

    /**
     * The boot's own warnings, errors in the order they show: the failed load, the database, the error
     * log, the periodic run's stall. They read a `BinlogCheck` of their own, so the stall warning and
     * the database warning share one read. foundation.md, "Site warnings".
     */
    private static function siteWarnings(): SiteWarnings
    {
        $binlog = new BinlogCheck();
        $warnings = new SiteWarnings();
        $warnings->add(new LoadFailureWarning());
        $warnings->add(new IsolationHealth($binlog));
        $warnings->add(new ErrorLogWarning());
        $warnings->add(new PeriodicRunHealth(new WordPressClock(), $binlog));

        return $warnings;
    }

    /** @return list<LoadFailure> */
    public function failures(): array
    {
        return $this->failures;
    }

    private function isCompatible(Api\Module\Module $module): bool
    {
        try {
            if (ApiVersionRange::parse($module->requiresApi())->allows(KALAKA_VERSION)) {
                return true;
            }
            $this->fail($module->id(), 'Requires API ' . $module->requiresApi() . ', core is ' . KALAKA_VERSION . '.');
        } catch (\Throwable $e) {
            $this->fail($module->id(), $e->getMessage(), $e);
        }

        return false;
    }

    private function fail(string $moduleId, string $reason, ?\Throwable $error = null): void
    {
        $failure = new LoadFailure($moduleId, $reason);
        $this->failures[] = $failure;
        if (!$error instanceof UpgradeInProgress) {
            $this->logged[] = [$failure, $error];
        }
    }

    /**
     * Logs the load failures only when they start or change, so a failure that lasts does not
     * write a line on every request; a clean load forgets them. A request that only found another
     * request upgrading the schema neither logs nor forgets.
     */
    private function logOnChange(): void
    {
        if ($this->logged === []) {
            if ($this->failures === [] && get_option(self::FAILURE_OPTION) !== false) {
                delete_option(self::FAILURE_OPTION);
            }

            return;
        }

        $fingerprint = md5((string) wp_json_encode(array_map(
            static fn (array $entry): array => [$entry[0]->moduleId, $entry[0]->reason],
            $this->logged,
        )));
        if (get_option(self::FAILURE_OPTION) === $fingerprint) {
            return;
        }

        foreach ($this->logged as [$failure, $error]) {
            if ($error !== null) {
                ErrorLog::failure('loading module ' . $failure->moduleId, $error);
            } else {
                ErrorLog::notice('module ' . $failure->moduleId . ' not loaded: ' . $failure->reason);
            }
        }
        update_option(self::FAILURE_OPTION, $fingerprint, true);
    }
}
