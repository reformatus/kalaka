<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

/**
 * An extension's proposed settings for its own fee component, carried by `ProposedMoneySettings` through
 * a money settings preview and apply (money.md, "Money settings apply"). The extension builds it through
 * its own `Api`; core composes `component()` in place of the registered component with the same ID for the
 * preview, and the apply runs `save()` first, then re-evaluates every open registration.
 * [UNAPPROVED N2]
 */
interface ProposedComponentSettings
{
    /** The ID of the registered fee component these settings are for. */
    public function componentId(): string;

    /** The component as it would compose with the proposed settings instead of the stored ones. */
    public function component(): FeeComponent;

    /** The audited use case that saves the proposed settings, run by the apply through the runner. */
    public function save(): SettingsSave;
}
