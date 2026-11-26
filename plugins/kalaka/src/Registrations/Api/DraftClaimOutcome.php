<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/** What a claim of a guest draft did (S74). */
enum DraftClaimOutcome: string
{
    /** The guest draft is the person's draft now: the cookie is done. */
    case Claimed = 'claimed';

    /**
     * The person has a draft of the form, and the link's answers change at least one of its values: they wait
     * for the person's choice; the cookie stays.
     */
    case Conflict = 'conflict';

    /**
     * The person has a draft of the form, and the link's answers change none of its values: settled as
     * keep_draft, with no choice to make; the guest draft is deleted, and the cookie is done.
     */
    case Settled = 'settled';

    /** The guest draft is for another form: the cookie stays for that one. */
    case OtherForm = 'other_form';

    /** No such guest draft (sent, swept or claimed): the cookie is done. */
    case Gone = 'gone';
}
