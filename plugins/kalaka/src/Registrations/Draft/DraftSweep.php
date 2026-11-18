<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Draft;

use Kalaka\Events\Api\Events;
use Kalaka\Events\Api\EventState;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Periodic\PeriodicStep;
use Kalaka\Foundation\Api\UseCase\Runner;

/**
 * The periodic run's step that deletes drafts that are done (S74): every draft of an event whose
 * registration has closed (its closing instant has passed), that is archived or gone, one run per event;
 * then the guest drafts whose lifetime has passed. An unpublished event keeps its drafts: it may open again.
 * [UNAPPROVED N8]
 */
final class DraftSweep implements PeriodicStep
{
    public function __construct(
        private readonly ?Runner $runner = null,
        private readonly DraftRepository $drafts = new DraftRepository(),
        private readonly Events $events = new Events(),
    ) {
    }

    public function name(): string
    {
        return 'drafts';
    }

    public function run(): void
    {
        $runner = $this->runner ?? Runner::current();
        $now = $runner->clock()->now();
        foreach ($this->drafts->events() as $eventId) {
            $event = $this->events->find($eventId);
            $closed = $event === null
                || $event->state() === EventState::Archived
                || ($event->registrationCloses !== null && $event->registrationCloses <= $now);
            if ($closed) {
                $runner->run(new SweepDrafts(), ['event_id' => $eventId], new SystemActor());
            }
        }
        $runner->run(new SweepDrafts(), [], new SystemActor());
    }
}
