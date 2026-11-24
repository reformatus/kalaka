<?php

declare(strict_types=1);

namespace Kalaka\Aid;

/** A row of `kalaka_aid_settings`: the event has adjudicated aid, which the organizers call `label`. */
final class AidSettings
{
    public function __construct(
        public readonly int $eventId,
        public string $label,
        public int $version = 0,
    ) {
    }
}
