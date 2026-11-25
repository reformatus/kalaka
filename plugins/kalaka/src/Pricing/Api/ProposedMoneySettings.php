<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Money\Money;

/**
 * Money settings proposed for an event, for a preview and an apply. A setting left null or not given stays
 * as it is. Core's fixed price, and each money extension's own settings as `ProposedComponentSettings` (S25
 * the price ladder, …), at most one per component.
 */
final readonly class ProposedMoneySettings
{
    /** @param list<ProposedComponentSettings> $components */
    public function __construct(
        public ?Money $fixedPrice = null,
        public array $components = [],
        public ?string $retention = null,
    ) {
    }

    /** The proposed settings for that component, or null when it keeps its stored ones. */
    public function forComponent(string $id): ?ProposedComponentSettings
    {
        foreach ($this->components as $settings) {
            if ($settings->componentId() === $id) {
                return $settings;
            }
        }

        return null;
    }
}
