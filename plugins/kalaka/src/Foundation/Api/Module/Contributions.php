<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Module;

use Kalaka\Forms\Api\BlockHost;
use Kalaka\Forms\Api\FieldType;
use Kalaka\Forms\Api\SlotRequirement;
use Kalaka\Forms\Api\ThemeAdapter;
use Kalaka\Foundation\Api\Periodic\PeriodicStep;
use Kalaka\Foundation\Api\Seed\Seeder;
use Kalaka\Foundation\Api\SiteWarning\SiteWarning;
use Kalaka\Registrations\Api\RequestSource;
use Kalaka\Registrations\Api\SeatClaimSource;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\Retention;
use Kalaka\Pricing\Api\RetentionRule;

/** Typed extension-point methods are added together with the first module that needs each. */
final class Contributions
{
    private static ?self $current = null;

    /** @var list<Seeder> */
    private array $seeders = [];

    /** @var list<SiteWarning> */
    private array $siteWarnings = [];

    /** @var array<string, FieldType> */
    private array $fieldTypes = [];

    /** @var list<SlotRequirement> */
    private array $slotRequirements = [];

    /** @var list<ThemeAdapter> */
    private array $themeAdapters = [];

    /** @var list<BlockHost> */
    private array $blockHosts = [];

    /** @var list<PeriodicStep> */
    private array $periodicSteps = [];

    /** @var list<RequestSource> */
    private array $requestSources = [];

    /** @var list<SeatClaimSource> */
    private array $seatClaimSources = [];
    /** @var array<string, FeeComponent> */
    private array $feeComponents = [];

    /** @var array<string, RetentionRule> */
    private array $retentionRules = [];

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
     * A requirement on an event's registration form is an outside point (modules-and-extensions.md, "The
     * extension surface"): participant field keys the form must hold (`Forms\Api\SlotRequirement`).
     */
    public function slotRequirement(SlotRequirement $requirement): void
    {
        $this->slotRequirements[] = $requirement;
    }

    /**
     * In contribution order.
     *
     * @return list<SlotRequirement>
     */
    public function slotRequirements(): array
    {
        return $this->slotRequirements;
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
     * Where requests come from: an inside point (modules-and-extensions.md, "The extension surface"). A module
     * that has requests to answer (support offers, aid) says which a registration has and writes the answer to
     * its own field.
     */
    public function requestSource(RequestSource $source): void
    {
        $this->requestSources[] = $source;
    }

    /**
     * Where a registration submit's seat claims come from, by the answers (`Registrations\Api\SeatClaimSource`). Data
     * only: the source locks and writes nothing, the submit's use case takes the seats.
     */
    public function seatClaimSource(SeatClaimSource $source): void
    {
        $this->seatClaimSources[] = $source;
    }

    /**
     * A fee component is an inside point (modules-and-extensions.md, "The extension surface"; money.md,
     * "Materializer"). Its ID is `[a-z][a-z0-9_]*`, at most 50 characters, and unique: a malformed or repeated
     * ID fails the contributing module (Pricing refuses one that is core's own).
     */
    public function feeComponent(FeeComponent $component): void
    {
        $id = $component->id();
        // `retention` is core's own line key (money.md, "Withdrawal, removal and substitution").
        if ($id === 'retention' || preg_match('/\A[a-z][a-z0-9_]{0,49}\z/', $id) !== 1) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a component ID, never output
            throw new \InvalidArgumentException('Invalid fee component ID ' . $id . '.');
        }
        if (isset($this->feeComponents[$id])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a component ID, never output
            throw new \InvalidArgumentException('Fee component already contributed: ' . $id . '.');
        }
        $this->feeComponents[$id] = $component;
    }

    /**
     * A retention rule is an inside point (modules-and-extensions.md, "The extension surface"). Its ID is
     * `[a-z][a-z0-9_]*`, at most 20 characters, and unique: a malformed or repeated ID fails the contributing
     * module; core's own two IDs are refused here too.
     */
    public function retentionRule(RetentionRule $rule): void
    {
        $id = $rule->id();
        if (in_array($id, Retention::CORE_RULES, true) || preg_match('/\A[a-z][a-z0-9_]{0,19}\z/', $id) !== 1) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a rule ID, never output
            throw new \InvalidArgumentException('Invalid retention rule ID ' . $id . '.');
        }
        if (isset($this->retentionRules[$id])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a rule ID, never output
            throw new \InvalidArgumentException('Retention rule already contributed: ' . $id . '.');
        }
        $this->retentionRules[$id] = $rule;
    }

    /**
     * In contribution order.
     *
     * @return list<RetentionRule>
     */
    public function retentionRules(): array
    {
        return array_values($this->retentionRules);
    }

    /**
     * In contribution order.
     *
     * @return list<RequestSource>
     */
    public function requestSources(): array
    {
        return $this->requestSources;
    }

    /**
     * In contribution order.
     *
     * @return list<SeatClaimSource>
     */
    public function seatClaimSources(): array
    {
        return $this->seatClaimSources;
    }

    /**
     * In contribution order.
     *
     * @return list<FeeComponent>
     */
    public function feeComponents(): array
    {
        return array_values($this->feeComponents);
    }
}
