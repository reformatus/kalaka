<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Module;

use Kalaka\Forms\Api\BlockHost;
use Kalaka\Forms\Api\FieldType;
use Kalaka\Forms\Api\ThemeAdapter;
use Kalaka\Foundation\Api\Periodic\PeriodicStep;
use Kalaka\Foundation\Api\Seed\Seeder;
use Kalaka\Foundation\Api\SiteWarning\SiteWarning;

/** Typed extension-point methods are added together with the first module that needs each. */
final class Contributions
{
    private static ?self $current = null;

    /** @var list<Seeder> */
    private array $seeders = [];

    /** @var list<SiteWarning> */
    private array $siteWarnings = [];

    /** @var list<PeriodicStep> */
    private array $periodicSteps = [];

    /** @var array<string, FieldType> */
    private array $fieldTypes = [];

    /** @var list<ThemeAdapter> */
    private array $themeAdapters = [];

    /** @var list<BlockHost> */
    private array $blockHosts = [];

    /** The contributions of this request's boot; empty before the boot has finished. */
    public static function current(): self
    {
        return self::$current ?? new self();
    }

    /**
     * Called by the boot only, once every module has contributed.
     *
     * @internal
     */
    public static function record(self $contributions): void
    {
        self::$current = $contributions;
    }

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

    /**
     * Work to run at the end of every periodic run (foundation.md, "Deferred work").
     */
    public function periodicStep(PeriodicStep $step): void
    {
        $this->periodicSteps[] = $step;
    }

    /**
     * In contribution order.
     *
     * @return list<PeriodicStep>
     */
    public function periodicSteps(): array
    {
        return $this->periodicSteps;
    }

    /**
     * A field type is an outside point (modules-and-extensions.md, "The extension surface"). One
     * type per block name; a second contribution for the same block fails the contributing module.
     */
    public function fieldType(FieldType $type): void
    {
        $name = $type->blockName();
        if (isset($this->fieldTypes[$name])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a block name, never output
            throw new \InvalidArgumentException('Field type already contributed for block ' . $name . '.');
        }
        $this->fieldTypes[$name] = $type;
    }

    /**
     * In contribution order.
     *
     * @return array<string, FieldType> by block name
     */
    public function fieldTypes(): array
    {
        return $this->fieldTypes;
    }

    /**
     * A theme adapter is an outside point (modules-and-extensions.md, "The extension surface"). At most
     * one applies: the first, in contribution order, that returns an adaptation for the active theme.
     */
    public function themeAdapter(ThemeAdapter $adapter): void
    {
        $this->themeAdapters[] = $adapter;
    }

    /**
     * In contribution order.
     *
     * @return list<ThemeAdapter>
     */
    public function themeAdapters(): array
    {
        return $this->themeAdapters;
    }

    /**
     * A post type other than a form whose editor may insert some of the form-only blocks (Forms'
     * `BlockGate`), e.g. the condition blocks in a mail body. Data only.
     */
    public function blockHost(BlockHost $host): void
    {
        $this->blockHosts[] = $host;
    }

    /**
     * In contribution order.
     *
     * @return list<BlockHost>
     */
    public function blockHosts(): array
    {
        return $this->blockHosts;
    }
}
