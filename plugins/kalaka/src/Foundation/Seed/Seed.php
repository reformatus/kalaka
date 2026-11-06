<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Seed;

use Kalaka\Foundation\Api\Clock;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Module\Module;
use Kalaka\Foundation\Api\Seed\Seeding;
use Kalaka\Foundation\Api\UseCase\Runner;

/** `wp kalaka seed`: the users, then every contributed seeder in contribution order. engineering.md, "Fixtures". */
final class Seed
{
    /** @param list<Module> $modules the modules that passed the version check, in boot order */
    public function __construct(
        private readonly array $modules,
        private readonly Contributions $contributions,
        private readonly Runner $runner,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param \Closure(string): void $report one call per line of progress
     * @return string the closing success message
     * @throws SeedFailure on a refusal or a failed step; what earlier steps committed stays
     */
    public function run(bool $drop, string $environmentType, \Closure $report): string
    {
        (new SeedGuard())->check($environmentType);

        // The load state is this request's boot: a failed module never contributed its seeders.
        $open = LoadState::current()->isOpen();
        if (!$open && !$drop) {
            $error = new SeedFailure('Refused: Kaláka did not load cleanly; run "wp kalaka seed --drop".');
            throw $error;
        }

        if ($drop) {
            (new SchemaDrop($this->modules))->run();
            if (!$open) {
                return 'Schema recreated; run "wp kalaka seed" again.';
            }
        }

        (new SeedUsers())->run($report);

        $seeding = new Seeding($this->runner, $this->clock);
        foreach ($this->contributions->seeders() as $seeder) {
            $report('Running seeder ' . $seeder::class . '.');
            $seeder->seed($seeding);
        }

        return 'Seeded.';
    }
}
