<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Seed;

/**
 * A module's or extension's sample data for development sites, contributed with
 * `Contributions::seeder()`. engineering.md, "Fixtures".
 */
interface Seeder
{
    /** Writes only through `Seeding::run()`. */
    public function seed(Seeding $seeding): void;
}
