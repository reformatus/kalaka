<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\ConfigurableFeeComponent;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Pricing\Api\ProposedComponentSettings;
use Kalaka\Pricing\Api\SettingsSave;
use Kalaka\Tests\Integration\Foundation\AdminProbe;

/** A test component at the extras stage whose one setting, `amount`, lives in memory; the participant owes it. */
final class ConfigurableTierComponent implements ConfigurableFeeComponent
{
    public function __construct(public ?string $amount, private readonly string $id = 'extra')
    {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function stage(): FeeStage
    {
        return FeeStage::Extras;
    }

    public function targets(FeeComponentInput $input): FeeTargets
    {
        return $this->amount === null
            ? FeeTargets::none()
            : new FeeTargets([Payer::PARTICIPANT => Money::of($this->amount, $input->currency)]);
    }

    public function settingsToArray(int $eventId): array
    {
        return ['amount' => $this->amount];
    }

    public function settingsFromArray(int $eventId, array $settings): ProposedComponentSettings
    {
        $amount = $settings['amount'] ?? null;
        if ($amount === 'violation') {
            throw new RuleViolation();
        }
        if (array_keys($settings) !== ['amount'] || ($amount !== null && !is_string($amount))) {
            throw new InvalidInput();
        }
        $save = new SettingsSave(new AdminProbe(function (array $input, Context $context) use ($amount): void {
            $this->amount = $amount;
        }), []);

        return new ProposedTestSettings($this->id, new self($amount, $this->id), $save);
    }
}
