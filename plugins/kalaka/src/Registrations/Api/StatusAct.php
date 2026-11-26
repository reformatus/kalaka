<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * The four acts that change a registration's status (registrations-and-identity.md, "Status, capacity and
 * waitlist"): admit from the waitlist, withdraw, remove and restore. Each is one staff act on one registration,
 * and withdraw is also the participant's own act (`WithdrawOwnRegistration`, by the secret-link actor); nothing else
 * changes a status after the submit set it. The table of what each allows and does is here, once:
 * the use cases, the preview and the detail read's `can` flags all read it. A restore is full access only: it
 * carries no grant action (`action()` is null). [UNAPPROVED N510]
 */
enum StatusAct: string
{
    case Admit = 'admit';
    case Withdraw = 'withdraw';
    case Remove = 'remove';
    case Restore = 'restore';

    /** The per-event grant action that carries the act (`Module::capabilities()`); null for a restore (full access only). */
    public function action(): ?string
    {
        return match ($this) {
            self::Admit => 'kalaka_admit_registration',
            self::Withdraw => 'kalaka_withdraw_registration',
            self::Remove => 'kalaka_remove_registration',
            self::Restore => null,
        };
    }

    /** The use case's name. */
    public function useCase(): string
    {
        return 'kalaka/' . $this->value . '-registration';
    }

    /**
     * The status the act leaves the registration in. A restore returns the registration to the status it left from:
     * `registered` when it was ever registered (`$wasRegistered`: it has a `registered_at`), `waitlisted` when it was
     * never registered. [UNAPPROVED N519]
     */
    public function target(bool $wasRegistered = true): RegistrationStatus
    {
        return match ($this) {
            self::Admit => RegistrationStatus::Registered,
            self::Restore => $wasRegistered ? RegistrationStatus::Registered : RegistrationStatus::Waitlisted,
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

    /**
     * Whether the registration already is where the act would put it, so the act is a repeat (`changed: false`). A
     * restore's repeat is a `registered` registration; a waitlisted one never left, so it is the wrong status.
     */
    public function isRepeatFrom(RegistrationStatus $status): bool
    {
        return $status === ($this === self::Restore ? RegistrationStatus::Registered : $this->target());
    }

    /** Whether the act needs a reason: none does (N514); kept in the shape, always false. [UNAPPROVED N514] */
    public function reasonRequired(): bool
    {
        return false;
    }

    /** Whether the act makes the registration `registered` (and so takes its seats again). */
    public function enters(bool $wasRegistered = true): bool
    {
        return $this->target($wasRegistered) === RegistrationStatus::Registered;
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
