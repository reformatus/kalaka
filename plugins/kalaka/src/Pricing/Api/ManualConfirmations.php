<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Decision\Decisions;
use Kalaka\Pricing\ManualConfirmationRepository;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The manual confirmation flag and staff's unconfirm (money.md, "Confirmation"): the per-event action that
 * sets the flag and unconfirms, the plain read of a registration's flag for display, and the one check of
 * whether an unconfirm is allowed now, which both `kalaka/unconfirm` and the detail read's `can.unconfirm` use.
 * The flag is set only through `kalaka/set-manual-confirmation` and revoked only by `kalaka/unconfirm`.
 * [UNAPPROVED N6, N12]
 */
final class ManualConfirmations
{
    /** The per-event action that lets a grant holder set the flag and unconfirm; full access implies it. */
    public const CONFIRM_MANUALLY = 'kalaka_confirm_manually';

    public function __construct(
        private readonly ManualConfirmationRepository $flags = new ManualConfirmationRepository(),
        private readonly Decisions $decisions = new Decisions(),
        private readonly ConfirmationRule $rule = new ConfirmationRule(),
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

    /** Whether the registration's current confirmation state is `confirmed` (only a `registered` one has one). */
    public function isConfirmed(RegistrationKey $registration): bool
    {
        $current = $this->decisions->currentOf($registration)[ConfirmationRule::KIND] ?? null;

        return $current !== null && $current->state === ConfirmationRule::CONFIRMED;
    }

    /**
     * Whether staff may unconfirm the registration now (the owner's ruling 2026-11-19): it is `confirmed`, not
     * because nothing was due, and its payments are below a threshold now, so they would not confirm it again.
     * Whether it was confirmed by payments or by the flag does not matter.
     *
     * @throws FeeComponentFailed when a component throws or returns something invalid
     */
    public function canUnconfirm(RegistrationKey $registration, \DateTimeImmutable $now): bool
    {
        $current = $this->decisions->currentOf($registration)[ConfirmationRule::KIND] ?? null;
        if ($current === null || $current->state !== ConfirmationRule::CONFIRMED) {
            return false;
        }
        // Nothing due confirms with the registration as its cause; payments and the flag name their own.
        if ($current->cause === null || $current->cause->type === 'registration') {
            return false;
        }

        return !$this->rule->paymentsQualify($registration, $now);
    }
}
