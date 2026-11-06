<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Module;

use Kalaka\Foundation\Api\Seed\Seeder;

/** Typed extension-point methods are added together with the first module that needs each. */
final class Contributions
{
    /** @var list<Seeder> */
    private array $seeders = [];

    public function seeder(Seeder $seeder): void
    {
        $this->seeders[] = $seeder;
    }

    /**
     * In contribution order: core modules in boot order, then extensions.
     *
     * @return list<Seeder>
     */
    public function seeders(): array
    {
        return $this->seeders;
    }
}
