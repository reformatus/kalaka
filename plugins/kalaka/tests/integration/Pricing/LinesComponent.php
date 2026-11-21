<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;

/** A test component that returns its lines kept apart, each owed `$amount`, under the given line keys. */
final class LinesComponent implements FeeComponent
{
    /** @param list<string> $keys the line keys it owes now */
    public function __construct(public array $keys, private readonly string $amount = '100')
    {
    }

    public function id(): string
    {
        return 'lined';
    }

    public function stage(): FeeStage
    {
        return FeeStage::Extras;
    }

    public function targets(FeeComponentInput $input): FeeTargets
    {
        $lines = [];
        foreach ($this->keys as $key) {
            $lines[$key] = [Payer::PARTICIPANT => Money::of($this->amount, Currency::HUF)];
        }

        return FeeTargets::ofLines($lines);
    }
}
