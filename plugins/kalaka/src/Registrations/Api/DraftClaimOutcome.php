<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/** What a claim of a guest draft did (S74). */
enum DraftClaimOutcome: string
{
    /** The guest draft is the person's draft now: the cookie is done. */
    case Claimed = 'claimed';

    /** The person has a draft of the form: the link's answers wait for their choice; the cookie stays. */
    case Conflict = 'conflict';

    /** The guest draft is for another form: the cookie stays for that one. */
    case OtherForm = 'other_form';

    /** No such guest draft, or it expired: the cookie is done. */
    case Gone = 'gone';
}
