<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Decision\Decisions;
use Kalaka\Pricing\ManualConfirmationRepository;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\Registrations;

/**
 * The manual confirmation flag and staff's unconfirm (money.md, "Confirmation"): the per-event action that
 * sets the flag and unconfirms, the plain read of a registration's flag for display, the check of whether an
 * unconfirm is offered (the registration is confirmed), and the dry run that warns whether it would confirm
 * again at once.
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
        private readonly Registrations $registrations = new Registrations(),
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

    /**
     * Whether the flag may be set on the registration: only a `registered` one (the owner's topic 1 ruling,
     * H3: confirmation applies only to registered). A plain read of one statement, without a lock.
     */
    public function canSet(RegistrationKey $registration): bool
    {
        return $this->registrations->find($registration)?->status === RegistrationStatus::Registered;
    }

    /** Whether the registration's current confirmation state is `confirmed` (only a `registered` one has one). */
    public function isConfirmed(RegistrationKey $registration): bool
    {
        $current = $this->decisions->currentOf($registration)[ConfirmationRule::KIND] ?? null;

        return $current !== null && $current->state === ConfirmationRule::CONFIRMED;
    }

    /**
     * When the registration's current `confirmed` record was recorded, or null when its confirmation state is
     * not `confirmed`. Read in the use case that holds the registration's lock, it is the record that is current
     * in the lock's order (not the order the attempts started in).
     */
    public function confirmedRecordedAt(RegistrationKey $registration): ?\DateTimeImmutable
    {
        $current = $this->decisions->currentOf($registration)[ConfirmationRule::KIND] ?? null;

        return $current !== null && $current->state === ConfirmationRule::CONFIRMED ? $current->recordedAt : null;
    }

    /**
     * The warning read before staff's unconfirm (money.md, "Confirmation"): whether confirmation would decide
     * `confirmed` again at once, the flag aside, and why. `'payments'` when the payments reach every required
     * threshold, `'nothing_due'` when nothing is due, null when it would stay unconfirmed or the registration
     * is not confirmed. A caller that has just read `isConfirmed()` passes `$knownConfirmed` to skip the second
     * read. A dry run of the second decision `kalaka/unconfirm` makes in the same pass; it writes
     * nothing and takes no lock.
     *
     * @return 'payments'|'nothing_due'|null
     * @throws FeeComponentFailed when a component throws or returns something invalid
     */
    public function reconfirmsOnUnconfirm(
        RegistrationKey $registration,
        \DateTimeImmutable $now,
        bool $knownConfirmed = false,
    ): ?string {
        if (!$knownConfirmed && !$this->isConfirmed($registration)) {
            return null;
        }

        return $this->rule->reconfirmsWith($registration, $now);
    }
}
