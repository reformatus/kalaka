<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * A fee component whose settings can be written down as plain data and read back: what the money settings
 * file of `wp kalaka money-settings` carries per component ID (money.md, "Money settings apply"). The data
 * is JSON-ready (strings, numbers, booleans, null, lists and maps); a component's own `Api` reads it, so
 * core knows nothing of a component's settings.
 */
interface ConfigurableFeeComponent extends FeeComponent
{
    /**
     * The event's current settings of this component, in the shape `settingsFromArray()` reads. An event
     * without any gives the empty form of the shape (no tiers, no items), never null.
     *
     * @return array<string, mixed>
     */
    public function settingsToArray(int $eventId): array;

    /**
     * The settings as written down, ready for a money settings preview and apply.
     *
     * @param array<array-key, mixed> $settings
     * @throws InvalidInput when the shape is not the one `settingsToArray()` gives, or the settings are not valid
     * @throws RuleViolation when a rule refuses the settings (the operator's text is its context; a caller names the
     *     reason by the error's code, `errorCode()`, not by its type)
     */
    public function settingsFromArray(int $eventId, array $settings): ProposedComponentSettings;
}
