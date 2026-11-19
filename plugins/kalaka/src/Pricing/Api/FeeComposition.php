<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Events\Api\Events;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Pricing\FeeComposer;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;

/**
 * The one fee composition (money.md, "Materializer"), read-only: the event's current settings, the
 * registration's facts and the registered fee components, composed stage by stage. The materializer records
 * its difference; a decision that reads a target (confirmation, later the late fee) runs the same composition
 * inside the decisions phase and writes nothing, so the target never comes from charge lines. [UNAPPROVED N1]
 */
final class FeeComposition
{
    public function __construct(
        private readonly CoreFeeComponents $components = new CoreFeeComponents(),
        private readonly FeeComposer $composer = new FeeComposer(),
        private readonly Events $events = new Events(),
        private readonly Registrations $registrations = new Registrations(),
    ) {
    }

    /**
     * The IDs of the components registered now: the materializer reverses a recorded line of one of them that the
     * composition no longer names.
     *
     * @return list<string>
     */
    public function componentIds(): array
    {
        return array_map(
            static fn (FeeComponent $component): string => $component->id(),
            $this->components->list()
        );
    }

    /**
     * What the registration owes now, or null when it or its event does not exist. Reads no ledger line.
     *
     * @throws FeeComponentFailed when a component throws or returns something invalid
     */
    public function of(RegistrationKey $key, \DateTimeImmutable $now): ?ComposedFee
    {
        $event = $this->events->find($key->eventId);
        if ($event === null) {
            return null;
        }
        $facts = $this->registrations->factsOf($key, $event);
        if ($facts === null) {
            return null;
        }

        return new ComposedFee(
            $facts,
            $event->currency,
            $this->composer->compose($facts, $event->currency, $now, $this->components->list())
        );
    }
}
