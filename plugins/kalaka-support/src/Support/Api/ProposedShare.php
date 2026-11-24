<?php

declare(strict_types=1);

namespace Kalaka\Support\Api;

use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\ProposedComponentSettings;
use Kalaka\Pricing\Api\SettingsSave;
use Kalaka\Support\Share\SetShare;
use Kalaka\Support\Share\ShareComponent;

/** Proposed share settings for an event, for a money settings preview and apply (`Share::propose()`). */
final readonly class ProposedShare implements ProposedComponentSettings
{
    /** @param array<string, mixed> $input `kalaka/set-share`'s input */
    public function __construct(public bool $on, private array $input)
    {
    }

    public function componentId(): string
    {
        return ShareComponent::ID;
    }

    public function component(): FeeComponent
    {
        return new ShareComponent(proposed: $this->on);
    }

    public function save(): SettingsSave
    {
        return new SettingsSave(new SetShare(), $this->input);
    }
}
