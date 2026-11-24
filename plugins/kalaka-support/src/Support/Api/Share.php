<?php

declare(strict_types=1);

namespace Kalaka\Support\Api;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Support\Share\ShareInput;
use Kalaka\Support\Share\ShareSettingsRepository;

/**
 * The event's congregation share for the settings screen (S72) and the money settings (preview → confirm →
 * apply; money.md, "Money settings apply"). Plain reads, outside any use case.
 */
final class Share
{
    public function __construct(
        private readonly ShareSettingsRepository $settings = new ShareSettingsRepository(),
        private readonly Events $events = new Events(),
        private readonly ShareInput $input = new ShareInput(),
    ) {
    }

    /** Whether the event's congregation share is on. */
    public function isOn(int $eventId): bool
    {
        return $this->settings->find($eventId) !== null;
    }

    /**
     * Proposed share settings: on or off.
     *
     * @throws NotFound when there is no such event
     * @throws InvalidInput when turning it on and another field holds one of the share's two keys;
     *     `kalaka/set-share` checks its schema again
     */
    public function propose(int $eventId, bool $on): ProposedShare
    {
        if ($this->events->find($eventId) === null) {
            throw new NotFound();
        }
        if ($on) {
            $this->input->checkKeysDeclarable($eventId);
        }

        return new ProposedShare($on, ['event_id' => $eventId, 'on' => $on]);
    }
}
