<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

use Kalaka\Mail\Catalogue\MailBody;

/** One mail body (a `kalaka_mail` post) of an event, as the event's mail page lists it. */
final readonly class MailBodyInfo
{
    /**
     * @param string $status WordPress's own post status
     * @param ?ActionMail $usedBy the action mail that has this body assigned; null when none
     * @param MailBody $facts what `UpdateActionMail::refuse()` needs to know of it
     */
    public function __construct(
        public int $id,
        public string $subject,
        public string $status,
        public ?ActionMail $usedBy,
        public \DateTimeImmutable $modified,
        public string $editUrl,
        public MailBody $facts,
    ) {
    }
}
