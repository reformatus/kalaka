<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * Whether a registration form needs an account (registrations-and-identity.md, "Accounts on the registration
 * form"), as its host gives it (`FormHost::accountStep()`). Either way the form shows to everyone with its
 * account block (`SignUp::BLOCK`), which opens WordPress's own login page in a window of its own (OP5). A host
 * that gives none has no accounts on the form: the block renders nothing, and a logged-in person is not the
 * owner.
 */
enum AccountStep: string
{
    /** The form cannot be sent without an account (the event's `mandatory` mode). */
    case Required = 'required';

    /** An account is offered; the form can be sent without one (the event's `optional` mode). */
    case Offered = 'offered';
}
