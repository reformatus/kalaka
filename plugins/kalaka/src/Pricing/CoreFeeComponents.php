<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\ProposedMoneySettings;

/**
 * The fee components registered now: core's fixed price, then the components extensions contribute
 * (`Contributions::feeComponent()`, read when listed, so after the boot), then any given here (tests).
 */
final class CoreFeeComponents
{
    /** @param list<FeeComponent> $more components besides the registered ones, composed after them */
    public function __construct(private readonly array $more = [])
    {
    }

    /**
     * @return list<FeeComponent> with the proposed settings in place of the stored ones, when given: core's
     *     fixed price, and a component that has `ProposedComponentSettings` replaced by its `component()`
     * @throws \LogicException when a component repeats another's ID, core's included, or its ID is not
     *     `[a-z][a-z0-9_]*`
     */
    public function list(?ProposedMoneySettings $proposed = null): array
    {
        $components = [new FixedPrice(proposed: $proposed?->fixedPrice)];
        $seen = [FixedPrice::ID => true];
        foreach ([...Contributions::current()->feeComponents(), ...$this->more] as $component) {
            $id = $component->id();
            // A colon would make `<id>:<item>` another component's ledger source (`Materializer`).
            if (preg_match('/\A[a-z][a-z0-9_]*\z/', $id) !== 1) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a component ID, never output
                throw new \LogicException('A fee component ID must match [a-z][a-z0-9_]*: ' . $id . '.');
            }
            if (isset($seen[$id])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a component ID, never output
                throw new \LogicException('Two fee components have the ID ' . $id . '.');
            }
            $seen[$id] = true;
            $components[] = $proposed?->forComponent($id)?->component() ?? $component;
        }

        return $components;
    }
}
