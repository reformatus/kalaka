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
}
