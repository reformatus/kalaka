<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Module;

use Kalaka\Foundation\Api\Seed\Seeder;
use Kalaka\Foundation\Api\SiteWarning\SiteWarning;

/** Typed extension-point methods are added together with the first module that needs each. */
final class Contributions
{
    /** @var list<Seeder> */
    private array $seeders = [];

    /** @var list<SiteWarning> */
    private array $siteWarnings = [];

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

    /** A standing condition to show as a site warning (foundation.md, "Site warnings"). */
    public function siteWarning(SiteWarning $warning): void
    {
        $this->siteWarnings[] = $warning;
    }

    /**
     * In contribution order.
     *
     * @return list<SiteWarning>
     */
    public function siteWarnings(): array
    {
        return $this->siteWarnings;
    }
}
