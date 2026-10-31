<?php

declare(strict_types=1);

namespace Kalaka\Foundation;

use Kalaka\Foundation\Api\LoadFailure;
use Kalaka\Foundation\Api\LoadState;

/**
 * Any load failure closes the site for this request (see LoadState):
 * modules-and-extensions.md, "Failure isolation".
 */
final class Boot
{
    /** @var list<LoadFailure> */
    private array $failures = [];

    /** @param list<Api\Module\Module> $coreModules in boot order */
    public function __construct(private readonly array $coreModules)
    {
    }

    public function run(): void
    {
        $set = new ModuleSet();
        foreach ($this->coreModules as $module) {
            $set->add($module);
        }

        try {
            do_action('kalaka_register_modules', $set);
        } catch (\Throwable $e) {
            $this->fail('kalaka_register_modules', $e->getMessage());
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
                $this->fail($module->id(), $e->getMessage());
            }
        }

        $contributions = new Api\Module\Contributions();
        foreach ($upgraded as $module) {
            try {
                $module->contribute($contributions);
                $module->boot();
            } catch (\Throwable $e) {
                $this->fail($module->id(), $e->getMessage());
            }
        }

        foreach ($set->failures() as $failure) {
            $this->fail($failure->moduleId, $failure->reason);
        }

        LoadState::record($this->failures);
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
            $this->fail($module->id(), $e->getMessage());
        }

        return false;
    }

    private function fail(string $moduleId, string $reason): void
    {
        $this->failures[] = new LoadFailure($moduleId, $reason);
        error_log('Kaláka: module ' . $moduleId . ' not loaded: ' . $reason);
    }
}
