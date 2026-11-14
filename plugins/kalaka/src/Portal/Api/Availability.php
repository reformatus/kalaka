<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

/**
 * Whether the participant blocks may show the registration (SB-11): only while its event is published.
 * An unpublished event (also every event while the site's load failed) is closed; an archived one too.
 */
enum Availability: string
{
    case Open = 'open';
    case EventClosed = 'event_closed';
    case Archived = 'archived';
}
