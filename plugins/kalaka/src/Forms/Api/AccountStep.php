<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * Whether a registration form needs an account (registrations-and-identity.md, "Accounts on the registration
 * form"), as its host gives it (`FormHost::accountStep()`). Someone not logged in is sent to WordPress's own
 * login page (`SignUp::url()`, OP5), which links to its registration page; with `Offered`, both pages have a
 * skip link back to the form. A host
 * that gives none has no accounts on the form: no sign-up, and a logged-in person is not the owner.
 */
enum AccountStep: string
{
    /** Sign up or log in first (the event's `mandatory` mode). */
    case Required = 'required';

    /** Sign up, log in, or skip (the event's `optional` mode). */
    case Offered = 'offered';
}
