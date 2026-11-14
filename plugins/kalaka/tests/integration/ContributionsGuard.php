<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration;

use Kalaka\Forms\Engine\ThemeAdapters;
use Kalaka\Foundation\Api\Module\Contributions;
use PHPUnit\Runner\AfterTestHook;
use PHPUnit\Runner\BeforeTestHook;

/**
 * Keeps each test's changes to the boot's contributions to that test. `Contributions::current()` is
 * static: a test that boots Foundation (`(new Boot(…))->run()`) or records its own contributions would
 * otherwise leave them, and lose the core field types, for every later test. Registered in
 * `phpunit.xml.dist`, so it covers every integration test, whatever its base class.
 */
final class ContributionsGuard implements BeforeTestHook, AfterTestHook
{
    private ?Contributions $before = null;

    public function executeBeforeTest(string $test): void
    {
        $this->before = Contributions::current();
    }

    public function executeAfterTest(string $test, float $time): void
    {
        if ($this->before !== null) {
            Contributions::record($this->before);
            $this->before = null;
        }
        ThemeAdapters::reset();
    }
}
