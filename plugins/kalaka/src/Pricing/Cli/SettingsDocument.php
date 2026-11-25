<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Cli;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Entry\CommandFailure;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\ParticipantFields\Api\FieldKeyHeld;
use Kalaka\Pricing\Api\ConfigurableFeeComponent;
use Kalaka\Pricing\Api\MoneySettings;
use Kalaka\Pricing\Api\PlainAmount;
use Kalaka\Pricing\Api\ProposedMoneySettings;
use Kalaka\Pricing\Api\RetentionRules;
use Kalaka\Pricing\CoreFeeComponents;

/**
 * The money settings of an event as one JSON document, what `wp kalaka money-settings` prints and reads:
 * `{"fixed_price": "15000", "retention": "everything_paid", "components": {"<component ID>": {…settings…}}}`.
 * Core writes
 * and reads the fixed price; every other component is a `ConfigurableFeeComponent` that does its own.
 * Reading follows `ProposedMoneySettings`: a `fixed_price` or `retention` that is null or missing, and a component not
 * named, stay as they are; what `show` prints read back changes nothing. [UNAPPROVED N2]
 */
final class SettingsDocument
{
    /** The code of kalaka-support's `OfferSelected`: an offer a save would change is selected by open registrations. */
    private const OFFER_SELECTED = 'kalaka_offer_selected';

    public function __construct(
        private readonly MoneySettings $settings = new MoneySettings(),
        private readonly CoreFeeComponents $components = new CoreFeeComponents(),
        private readonly Events $events = new Events(),
    ) {
    }

    /**
     * @return array{fixed_price: ?string, retention: string, components: array<string, array<string, mixed>>}
     * @throws NotFound when there is no such event
     */
    public function show(int $eventId): array
    {
        $this->events->find($eventId) ?? throw new NotFound();
        $components = [];
        foreach ($this->configurable() as $id => $component) {
            $components[$id] = $component->settingsToArray($eventId);
        }
        $price = $this->settings->fixedPrice($eventId);

        return [
            'fixed_price' => $price === null ? null : PlainAmount::of($price),
            'retention' => $this->settings->retention($eventId)->id(),
            'components' => $components,
        ];
    }

    /**
     * @param mixed $document the decoded JSON
     * @throws CommandFailure when the document is not the shape `show` prints, or a component's settings are not valid
     * @throws NotFound when there is no such event
     */
    public function proposed(int $eventId, mixed $document): ProposedMoneySettings
    {
        $event = $this->events->find($eventId) ?? throw new NotFound();
        if (!is_array($document) || ($document !== [] && array_is_list($document))) {
            $error = new CommandFailure('The file must hold a JSON object with "fixed_price" and "components".');
            throw $error;
        }
        $unknown = array_diff(array_keys($document), ['fixed_price', 'retention', 'components']);
        if ($unknown !== []) {
            $names = implode(', ', array_map('strval', $unknown));
            $error = new CommandFailure('Unknown key in the file: ' . $names . '.');
            throw $error;
        }

        $price = $document['fixed_price'] ?? null;
        $badPrice = '"fixed_price" must be a decimal string such as "15000", or null.';
        if ($price !== null && !is_string($price)) {
            $error = new CommandFailure($badPrice);
            throw $error;
        }
        // The text's form and range are `Money::of()`'s, its sign `MoneySettings::check()`'s: no pattern of the
        // CLI's own beside the use case's input schema.
        try {
            $fixed = $price === null ? null : Money::of($price, $event->currency);
        } catch (InvalidInput $invalid) {
            $error = new CommandFailure($badPrice, $invalid);
            throw $error;
        }

        $retention = null;
        if (($document['retention'] ?? null) !== null) {
            $given = $document['retention'];
            $retention = is_string($given) && RetentionRules::find($given) !== null ? $given : null;
            if ($retention === null) {
                $error = new CommandFailure(
                    '"retention" must be one of: ' . implode(', ', RetentionRules::ids()) . '.'
                );
                throw $error;
            }
        }

        $given = $document['components'] ?? [];
        if (!is_array($given) || ($given !== [] && array_is_list($given))) {
            $error = new CommandFailure('"components" must be a JSON object keyed by component ID.');
            throw $error;
        }
        $configurable = $this->configurable();
        $proposed = [];
        foreach ($given as $id => $settings) {
            $id = (string) $id;
            if (!isset($configurable[$id])) {
                $error = new CommandFailure('No component has settings under the ID "' . $id . '".');
                throw $error;
            }
            if (!is_array($settings)) {
                $error = new CommandFailure('The settings of "' . $id . '" must be a JSON object.');
                throw $error;
            }
            try {
                $proposed[] = $configurable[$id]->settingsFromArray($eventId, $settings);
            } catch (InvalidInput $invalid) {
                $error = new CommandFailure('The settings of "' . $id . '" are not valid.', $invalid);
                throw $error;
            } catch (FieldKeyHeld $refused) {
                $error = new CommandFailure(
                    'The settings of "' . $id . '" are refused: a key they declare is held by a participant field of '
                    . 'the event that code may not take over (a staff or system field, a field of another type, '
                    . 'or another declarer\'s field).',
                    $refused
                );
                throw $error;
            } catch (RuleViolation $refused) {
                // By code, not by type: only an offer selected by open registrations is named here; any other
                // rule violation goes on to the command's boundary as it is.
                if ($refused->errorCode() !== self::OFFER_SELECTED) {
                    throw $refused;
                }
                $error = new CommandFailure(
                    'The settings of "' . $id . '" are refused: an offer is selected by open registrations.',
                    $refused
                );
                throw $error;
            }
        }

        return new ProposedMoneySettings($fixed, $proposed, $retention);
    }

    /** @return array<string, ConfigurableFeeComponent> by component ID, in registration order */
    private function configurable(): array
    {
        $found = [];
        foreach ($this->components->list() as $component) {
            if ($component instanceof ConfigurableFeeComponent) {
                $found[$component->id()] = $component;
            }
        }

        return $found;
    }
}
