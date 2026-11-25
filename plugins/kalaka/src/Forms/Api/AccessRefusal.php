<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/** Why a form cannot be used right now; the values are the codes of the requirements. */
enum AccessRefusal: string
{
    case EventNotOpen = 'event_not_open';
    case OutsideRegistrationWindow = 'outside_registration_window';
    case PasswordRequired = 'password_required';
    case RoleRequired = 'role_required';
    /** S15: someone not logged in submits a form whose event's account mode is mandatory. */
    case AccountRequired = 'account_required';
    /** An additional form outside its open period. */
    case OutsideOpenPeriod = 'outside_open_period';
    /** An additional form with no active registration of its event to write to. */
    case NoActiveRegistration = 'no_active_registration';
    /** An additional form whose registration is not `registered` and not `waitlisted` (withdrawn or removed). */
    case RegistrationMayNotSubmit = 'registration_may_not_submit';
    /**
     * An additional form whose registration is `waitlisted` (owner ruling 2026-11-25): the page shows the
     * registration's status in its waitlisted state instead of the form.
     */
    case RegistrationWaitlisted = 'registration_waitlisted';
}
