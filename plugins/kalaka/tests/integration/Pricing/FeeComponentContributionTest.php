<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Foundation\Api\Module\Contributions;
use WP_UnitTestCase;

/** `Contributions::feeComponent()`: an ID of the component pattern, contributed once. */
final class FeeComponentContributionTest extends WP_UnitTestCase
{
    public function testComponentsAreListedInContributionOrder(): void
    {
        $contributions = new Contributions();
        $contributions->feeComponent(new TierComponent('1', 'ladder'));
        $contributions->feeComponent(new TierComponent('1', 'lunch'));

        self::assertSame(
            ['ladder', 'lunch'],
            array_map(static fn ($component): string => $component->id(), $contributions->feeComponents())
        );
    }

    public function testAMalformedIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Contributions())->feeComponent(new TierComponent('1', 'Árlépcső'));
    }

    public function testTheIdRetentionIsCoresOwnAndIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Contributions())->feeComponent(new TierComponent('1', 'retention'));
    }

    public function testAnIdContributedTwiceIsRefused(): void
    {
        $contributions = new Contributions();
        $contributions->feeComponent(new TierComponent('1', 'ladder'));

        $this->expectException(\InvalidArgumentException::class);
        $contributions->feeComponent(new TierComponent('1', 'ladder'));
    }
}
