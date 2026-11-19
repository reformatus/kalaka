<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Error\KalakaException;

/**
 * A choice on a link's answers that cannot be applied (S74, pass 2): the request carries no claim token,
 * its guest draft is gone or expired, it is for another form, or the claim was already settled with another
 * choice (or without one, on the form's page). A repeat of the choice that settled it is no refusal: it
 * answers as the first did (`ResolveDraftClaim`). Not audited: nothing was changed.
 */
final class DraftClaimRefused extends KalakaException
{
    protected function defaultMessage(): string
    {
        // [UNAPPROVED N11] The claim popup shows it, with a button that reloads the page (owner, 2026-11-17).
        return 'Ezt most nem tudjuk végrehajtani: a bejövő válaszokról már döntöttél (például egy másik lapon), '
            . 'vagy ezek a válaszok már nem érvényesek. Töltsd újra az oldalt.';
    }

    public function errorCode(): string
    {
        return 'kalaka_draft_claim_refused';
    }

    public function status(): int
    {
        return 409;
    }

    public function isAudited(): bool
    {
        return false;
    }
}
