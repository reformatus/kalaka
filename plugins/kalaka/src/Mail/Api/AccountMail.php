<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

use Kalaka\Mail\Body\AccountLinkRule;

/** What the "Jelentkezés rögzítve" mail must be for an event to take an account mode (`AccountLinkRule`). */
final class AccountMail
{
    /** The refusal of a change of the event to `mandatory` or `optional`, or null when the mail is ready. */
    public function problem(int $eventId): ?string
    {
        return (new AccountLinkRule())->modeProblem($eventId);
    }
}
