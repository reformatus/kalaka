<?php

declare(strict_types=1);

namespace Kalaka\Deposits\Api;

use Kalaka\Deposits\DepositComponent;
use Kalaka\Deposits\SetDeposit;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\ProposedComponentSettings;
use Kalaka\Pricing\Api\SettingsSave;

/** Proposed deposit settings for an event, for a money settings preview and apply (`Deposits::propose()`). */
final readonly class ProposedDeposit implements ProposedComponentSettings
{
    /** @param array<string, mixed> $input `kalaka/set-deposit`'s input */
    public function __construct(public ?string $amount, private array $input)
    {
    }

    public function componentId(): string
    {
        return DepositComponent::ID;
    }

    public function component(): FeeComponent
    {
        return new DepositComponent(proposed: ['amount' => $this->amount]);
    }

    public function save(): SettingsSave
    {
        return new SettingsSave(new SetDeposit(), $this->input);
    }
}
