<?php

declare(strict_types=1);

namespace Kalaka\Aid\Api;

use Kalaka\Aid\AidComponent;
use Kalaka\Aid\SetAid;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\ProposedComponentSettings;
use Kalaka\Pricing\Api\SettingsSave;

/** Proposed aid settings for an event, for a money settings preview and apply (`Aid::propose()`). */
final readonly class ProposedAid implements ProposedComponentSettings
{
    /** @param array<string, mixed> $input `kalaka/set-aid`'s input */
    public function __construct(public ?string $label, private array $input)
    {
    }

    public function componentId(): string
    {
        return AidComponent::ID;
    }

    public function component(): FeeComponent
    {
        return new AidComponent(proposed: ['label' => $this->label]);
    }

    public function save(): SettingsSave
    {
        return new SettingsSave(new SetAid(), $this->input);
    }
}
