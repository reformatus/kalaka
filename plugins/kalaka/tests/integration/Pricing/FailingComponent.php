<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;

/** A test component that throws for one registration code while `$failing` is set, and owes nothing otherwise. */
final class FailingComponent implements FeeComponent
{
    public bool $failing = false;

    public function __construct(private readonly string $code)
    {
    }

    public function id(): string
    {
        return 'flaky';
    }

    public function stage(): FeeStage
    {
        return FeeStage::Extras;
    }

    public function targets(FeeComponentInput $input): FeeTargets
    {
        if ($this->failing && $input->registration->code === $this->code) {
            throw new \RuntimeException('Test component failure.');
        }

        return FeeTargets::none();
    }
}
