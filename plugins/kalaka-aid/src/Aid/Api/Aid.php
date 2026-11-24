<?php

declare(strict_types=1);

namespace Kalaka\Aid\Api;

use Kalaka\Aid\AidInput;
use Kalaka\Aid\AidSettingsRepository;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * The event's adjudicated aid for every other module and the settings screen (S72): the label the organizers gave
 * it, and proposed settings to hand to `Pricing\Api\MoneySettings` (preview → confirm → apply; money.md, "Money
 * settings apply"). Plain reads, outside any use case.
 */
final class Aid
{
    public function __construct(
        private readonly AidSettingsRepository $settings = new AidSettingsRepository(),
        private readonly Events $events = new Events(),
        private readonly AidInput $input = new AidInput(),
    ) {
    }

    /** The organizers' name for the event's aid, or null when the event has none. */
    public function label(int $eventId): ?string
    {
        return $this->settings->find($eventId)?->label;
    }

    /**
     * Proposed aid settings: the label the organizers give the aid (1 to 191 characters), or null to turn the
     * event's aid off.
     *
     * @throws NotFound when there is no such event
     * @throws InvalidInput when the label is not valid or is „Kedvezmény”; `kalaka/set-aid` checks its schema again
     * @throws RuleViolation when a field key aid needs is held by a field it may not declare [UNAPPROVED N361]
     *     (`ParticipantFields::checkDeclarable()`: a staff or system field, another type, another declarer)
     */
    public function propose(int $eventId, ?string $label): ProposedAid
    {
        if ($this->events->find($eventId) === null) {
            throw new NotFound();
        }
        if ($label !== null) {
            $trimmed = trim($label);
            if ($trimmed === '' || mb_strlen($trimmed, 'UTF-8') > AidInput::MAX_LABEL) {
                throw new InvalidInput();
            }
            $this->input->assertLabelAllowed($trimmed);
            $label = $trimmed;
            $this->input->checkKeysDeclarable($eventId);
        }

        return new ProposedAid($label, ['event_id' => $eventId, 'label' => $label]);
    }
}
