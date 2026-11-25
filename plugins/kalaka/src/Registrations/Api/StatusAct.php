<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * The four acts that change a registration's status (registrations-and-identity.md, "Status, capacity and
 * waitlist"): admit from the waitlist, withdraw, remove and restore. Each is one staff act on one registration;
 * nothing else changes a status after the submit set it. The table of what each allows and does is here, once:
 * the use cases, the preview and the detail read's `can` flags all read it. [UNAPPROVED N510]
 */
enum StatusAct: string
{
    case Admit = 'admit';
    case Withdraw = 'withdraw';
    case Remove = 'remove';
    case Restore = 'restore';

    /** The per-event grant action that carries the act (`Module::capabilities()`). */
    public function action(): string
    {
        return match ($this) {
            self::Admit => 'kalaka_admit_registration',
            self::Withdraw => 'kalaka_withdraw_registration',
            self::Remove => 'kalaka_remove_registration',
            self::Restore => 'kalaka_restore_registration',
        };
    }

    /** The use case's name. */
    public function useCase(): string
    {
        return 'kalaka/' . $this->value . '-registration';
    }

    /** The status the act leaves the registration in: a restore always returns to `registered`. [UNAPPROVED N519] */
    public function target(): RegistrationStatus
    {
        return match ($this) {
            self::Admit, self::Restore => RegistrationStatus::Registered,
            self::Withdraw => RegistrationStatus::Withdrawn,
            self::Remove => RegistrationStatus::Removed,
        };
    }

    /** Whether the act may start from the status. A registration already in the target is a repeat, not allowed. */
    public function allowedFrom(RegistrationStatus $status): bool
    {
        return match ($this) {
            self::Admit => $status === RegistrationStatus::Waitlisted,
            self::Withdraw, self::Remove => $status === RegistrationStatus::Registered
                || $status === RegistrationStatus::Waitlisted,
            self::Restore => $status === RegistrationStatus::Withdrawn || $status === RegistrationStatus::Removed,
        };
    }

    /** Whether the act makes the registration `registered` (and so takes its seats again). */
    public function enters(): bool
    {
        return $this->target() === RegistrationStatus::Registered;
    }

    /** Whether the act needs a reason: removing does; the others take one optionally. [UNAPPROVED N514] */
    public function reasonRequired(): bool
    {
        return $this === self::Remove;
    }

    /** The registration's own act that takes the seats again; null for an act that leaves. */
    public function seatAct(): ?SeatAct
    {
        return match ($this) {
            self::Admit => SeatAct::Admission,
            self::Restore => SeatAct::Restore,
            default => null,
        };
    }

    /** What `registered_by` records for the act; null for an act that leaves. */
    public function registeredBy(): ?RegisteredBy
    {
        return match ($this) {
            self::Admit => RegisteredBy::Admission,
            self::Restore => RegisteredBy::Restore,
            default => null,
        };
    }
}
