<?php

declare(strict_types=1);

namespace Kalaka\Extras\Api;

use Kalaka\Extras\Charges\ChargeField;
use Kalaka\Extras\Charges\SetExtraCharges;
use Kalaka\Extras\ExtrasComponent;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\ProposedComponentSettings;
use Kalaka\Pricing\Api\SettingsSave;

/** Proposed extra charges for an event, for a money settings preview and apply (`Extras::propose()`). */
final readonly class ProposedExtras implements ProposedComponentSettings
{
    /**
     * @param list<Charge> $charges the charges as read from the input
     * @param array<string, mixed> $input `kalaka/set-extra-charges`'s input
     */
    public function __construct(public array $charges, private array $input)
    {
    }

    public function componentId(): string
    {
        return ExtrasComponent::ID;
    }

    public function component(): FeeComponent
    {
        return new ExtrasComponent(proposed: $this->charges);
    }

    /**
     * The save, after the forms are looked at once more: they may have changed since the preview, and the save's
     * own transaction may not read form posts. `MoneySettings::apply()` asks before it writes anything.
     *
     * @throws \Kalaka\ParticipantFields\Api\FieldDecimalsHeld when a form holds a number charge's key with
     *     decimals
     */
    public function save(): SettingsSave
    {
        $charges = $this->input['charges'] ?? [];
        foreach (is_array($charges) ? $charges : [] as $charge) {
            if (is_array($charge) && ($charge['type'] ?? Charge::CONSENT) === Charge::NUMBER) {
                (new ChargeField())->checkForms((int) $this->input['event_id'], (string) $charge['key']);
            }
        }

        return new SettingsSave(new SetExtraCharges(), $this->input);
    }
}
