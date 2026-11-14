<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * Whether a form begins with the account step (registrations-and-identity.md, "Accounts on the
 * registration form"), as its host gives it (`FormHost::accountStep()`). The step shows only to someone not
 * logged in, before the form's first page: sign up or log in, and with `Offered` skip too. A host that gives
 * none has no accounts on the form: no step, and a logged-in person is not the owner.
 */
enum AccountStep: string
{
    /** Sign up or log in. */
    case Required = 'required';

    /** Sign up, log in or skip. */
    case Offered = 'offered';
}
