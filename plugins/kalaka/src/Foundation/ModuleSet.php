<?php

declare(strict_types=1);

namespace Kalaka\Foundation;

use Kalaka\Foundation\Api\LoadFailure;
use Kalaka\Foundation\Api\Module\Modules;

final class ModuleSet implements Modules
{
    /** @var array<string, Api\Module\Module> */
    private array $modules = [];

    /** @var list<LoadFailure> */
    private array $failures = [];

    public function add(Api\Module\Module $module): void
    {
        $id = $module->id();
        if (preg_match('/^[a-z][a-z0-9_]*$/', $id) !== 1) {
            $this->failures[] = new LoadFailure($id, 'Invalid module id.');
            return;
        }
        if (isset($this->modules[$id])) {
            $this->failures[] = new LoadFailure($id, 'Duplicate module id.');
            return;
        }
        $this->modules[$id] = $module;
    }

    /** @return list<Api\Module\Module> */
    public function all(): array
    {
        return array_values($this->modules);
    }

    /** @return list<LoadFailure> */
    public function failures(): array
    {
        return $this->failures;
    }
}
