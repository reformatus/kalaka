<?php

declare(strict_types=1);

namespace Kalaka\Mail\Catalogue;

use Kalaka\Mail\Api\ActionMail;

/**
 * One event's setting of one action mail: on or off, its body (a `kalaka_mail` post) and whether the
 * event's layout wraps it. Without a stored row the setting is the default: off, no body, wrapped.
 */
final class ActionMailEntry
{
    public function __construct(
        public readonly int $eventId,
        public readonly ActionMail $action,
        public bool $enabled = false,
        public ?int $bodyId = null,
        public bool $layout = true,
        public int $version = 0,
    ) {
    }
}
