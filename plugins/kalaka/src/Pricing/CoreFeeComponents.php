<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\ProposedMoneySettings;

/**
 * The fee components registered now. In S19 only core's fixed price; the extension point
 * (`Contributions::feeComponent()`) comes with the first extension that needs it (S25), and a component with
 * settings then also takes its proposed settings for a preview.
 */
final class CoreFeeComponents
{
    /** @return list<FeeComponent> with the proposed settings in place of the stored ones, when given */
    public function list(?ProposedMoneySettings $proposed = null): array
    {
        return [new FixedPrice(proposed: $proposed?->fixedPrice)];
    }
}
