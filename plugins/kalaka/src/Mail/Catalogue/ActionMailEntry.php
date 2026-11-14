<?php

declare(strict_types=1);

namespace Kalaka\Mail\Catalogue;

use Kalaka\Mail\Api\ActionMail;

/**
 * One event's setting of one action mail: on or off, its body (a `kalaka_mail` post) and whether the
 * event's layout wraps it. Without a stored row the setting is the default: off, no body, wrapped.
 *
 * A stored row may carry a key that is not one of the catalogue's actions (a mail of an extension, or
 * a row a later version wrote): `$action` is then null and `$mail` keeps the stored key, and the
 * readers skip it (`ActionMailRepository::ofEvent()`).
 */
final class ActionMailEntry
{
    /** The stored key: the action's, or the unknown key of a stored row. */
    public readonly string $mail;

    public function __construct(
        public readonly int $eventId,
        public readonly ?ActionMail $action,
        public bool $enabled = false,
        public ?int $bodyId = null,
        public bool $layout = true,
        public int $version = 0,
        ?string $mail = null,
    ) {
        $this->mail = $mail ?? $action->value ?? '';
    }
}
