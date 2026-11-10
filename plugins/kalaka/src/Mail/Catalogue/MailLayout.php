<?php

declare(strict_types=1);

namespace Kalaka\Mail\Catalogue;

/** An event's layout: the `kalaka_mail` post that wraps its mails (mail.md, "Configuration"). */
final class MailLayout
{
    public function __construct(
        public readonly int $eventId,
        public int $layoutId,
        public int $version = 0,
    ) {
    }
}
