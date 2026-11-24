<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;

/** A test component whose targets are a closure of the test. */
final class ClosureComponent implements FeeComponent
{
    /** @param \Closure(FeeComponentInput): FeeTargets $targets */
    public function __construct(
        private readonly string $id,
        private readonly FeeStage $stage,
        private readonly \Closure $targets,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function stage(): FeeStage
    {
        return $this->stage;
    }

    public function targets(FeeComponentInput $input): FeeTargets
    {
        return ($this->targets)($input);
    }
}
