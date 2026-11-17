<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Forms\Api\SignUp;
use Kalaka\Registrations\Account\SignUpPage;

/**
 * What another module may know of an owner account (S15): whether it is one Kaláka created whose password
 * was never set (`kalaka_unverified`), and its set-password link. The mail's context reads them
 * (`account.unverified`, `account.password_link`).
 */
final class Accounts
{
    /**
     * [UNAPPROVED N27] The registration form a WordPress login, registration or reset request is for, from its
     * marker (`SignUp::MARKER`): a published registration form whose published event has accounts; null
     * otherwise. Kaláka adds no field of its own to the registration page. An extension that does (e.g. the
     * draft reminders' opt-in) uses WordPress's own hooks (`register_form`, `registration_errors`,
     * `user_register`) and asks this whether the request is a Kaláka sign-up, and for which form.
     */
    public function signUpForm(): ?int
    {
        return (new SignUpPage())->markedForm();
    }

    /** Whether the account is one Kaláka created whose password was never set through a reset link. */
    public function isUnverified(int $userId): bool
    {
        return SignUpPage::isUnverified($userId);
    }

    /**
     * A fresh link to WordPress's own "set new password" screen for the account
     * (`wp-login.php?action=rp&key=…&login=…`), from `get_password_reset_key()`. WordPress keeps one key per
     * user, so making one replaces the last: the newest link wins. It writes WordPress's user row, so it
     * runs outside any use case (the sender renders a mail outside one). Null when there is no such user or
     * WordPress refuses a key.
     *
     * With `$formId` (the registration's slot form), the link carries the form's marker (`SignUp::MARKER`):
     * WordPress keeps it through its reset screens, and the login after the password is set returns to the
     * form's page, resolved on the server (`SignUp::page()`; OP1, owner 2026-11-17).
     */
    public function passwordLink(int $userId, ?int $formId = null): ?string
    {
        $user = get_userdata($userId);
        if (!$user instanceof \WP_User) {
            return null;
        }
        $key = get_password_reset_key($user);
        if ($key instanceof \WP_Error) {
            return null;
        }

        $link = network_site_url(
            'wp-login.php?action=rp&key=' . rawurlencode($key) . '&login=' . rawurlencode($user->user_login),
            'login'
        );

        return $formId === null ? $link : $link . '&' . SignUp::MARKER . '=' . $formId;
    }
}
