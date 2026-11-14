<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * What the person chose in the account step (`AccountStep`), as the form page sends it with a submit: sign
 * up with the form's contact email and display name, or skip (only where the step offers it). Logging in
 * leaves the page, so it is never sent: a logged-in submit carries no choice.
 */
enum AccountChoice: string
{
    case SignUp = 'signup';
    case Skip = 'skip';
}
