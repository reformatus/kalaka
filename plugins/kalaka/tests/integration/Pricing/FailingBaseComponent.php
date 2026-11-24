<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Pricing\Api\ProvidesBase;

/** A test component whose answer to "does it provide the base" always throws. */
final class FailingBaseComponent implements FeeComponent, ProvidesBase
{
    public function id(): string
    {
        return 'flaky';
    }

    public function stage(): FeeStage
    {
        return FeeStage::Extras;
    }

    public function providesBase(int $eventId): bool
    {
        throw new \RuntimeException('Test base failure.');
    }

    public function targets(FeeComponentInput $input): FeeTargets
    {
        return FeeTargets::none();
    }
}
