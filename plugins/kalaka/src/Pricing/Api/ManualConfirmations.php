<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Pricing\ManualConfirmationRepository;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The manual confirmation flag (money.md, "Confirmation"): the per-event action that sets and revokes it, and
 * the plain read of a registration's flag for display. The flag is set and revoked only through
 * `kalaka/set-manual-confirmation` and `kalaka/revoke-manual-confirmation`. [UNAPPROVED N6]
 */
final class ManualConfirmations
{
    /** The per-event action that lets a grant holder set and revoke the flag; full access implies it. */
    public const CONFIRM_MANUALLY = 'kalaka_confirm_manually';

    public function __construct(
        private readonly ManualConfirmationRepository $flags = new ManualConfirmationRepository(),
    ) {
    }

    /** The registration's flag while it is set, or null; a plain read of one statement, without a lock. */
    public function of(RegistrationKey $registration): ?ManualConfirmation
    {
        $flag = $this->flags->find($registration);
        if ($flag === null || !$flag->isSet()) {
            return null;
        }

        return new ManualConfirmation($flag->setAt, $flag->setBy, $flag->reason);
    }
}
