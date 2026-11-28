<?php

declare(strict_types=1);

namespace Kalaka\Share\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\ProposedComponentSettings;
use Kalaka\Pricing\Api\SettingsSave;
use Kalaka\Share\SetShare;
use Kalaka\Share\ShareComponent;
use Kalaka\Share\ShareInput;

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

    /**
     * Asked for before any save of an apply runs: turning the share on is refused here when a field holds one of
     * its keys that the share may not declare, so a stale proposal saves nothing, not even the fixed price or
     * another component.
     *
     * @throws RuleViolation when a held key is not declarable by the share
     */
    public function save(): SettingsSave
    {
        if ($this->on) {
            (new ShareInput())->checkKeysDeclarable((int) $this->input['event_id']);
        }

        return new SettingsSave(new SetShare(), $this->input);
    }
}
