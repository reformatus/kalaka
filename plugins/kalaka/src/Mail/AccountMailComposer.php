<?php

declare(strict_types=1);

namespace Kalaka\Mail;

use Kalaka\Events\Api\Events;
use Kalaka\Mail\Api\AccountMail;
use Kalaka\Registrations\Api\SecretLinks;

/**
 * The composer of the set-password mail (S15), a system mail with plain built-in content beside the
 * action-mail catalogue (S33's `Body\ActionMailComposer` hands it here; its body is re-asked in S33/S34).
 * The submit of a registration by an account Kaláka created whose password was never set queues it, with
 * the cause `account` and the WordPress user ID as its key (`Registrations\Account\AccountOutcome`); it goes
 * to that account's current address.
 *
 * Its link is WordPress's own "set new password" screen (`wp-login.php?action=rp`) with a fresh reset key from
 * `get_password_reset_key()`, made when the mail is composed so a wait in the queue does not use up its
 * lifetime (7 days for such an account, `Registrations\Account\SignUpPage`). Setting the password checks
 * the address. The mail carries the registration's secret link too, on the site's home page (re-asked in
 * S33/S34). Any other delivery has no recipient here.
 */
final class AccountMailComposer implements MailComposer
{
    /** The version of the built-in body. */
    public const VERSION = 'kalaka-account-2';

    // [UNAPPROVED T4] the set-password mail's texts.
    public const SET_PASSWORD_SUBJECT = 'Állítsd be a jelszavadat';

    public const SET_PASSWORD_TEXT = 'A regisztrációddal létrejött a fiókod. Hogy később is be tudj jelentkezni, '
        . 'állítsd be a jelszavadat ezen a linken (7 napig érvényes):';

    public const SET_PASSWORD_LINK = 'Jelszó beállítása';

    public const STATUS_TEXT = 'A jelentkezésed állapotát ezen a linken nézheted meg:';

    public function __construct(
        private readonly Events $events = new Events(),
        private readonly SecretLinks $secretLinks = new SecretLinks(),
    ) {
    }

    /** Whether the delivery is the set-password mail. */
    public static function handles(Delivery $delivery): bool
    {
        return AccountMail::tryFrom($delivery->mail) !== null;
    }

    /** @throws RecipientUnavailable when the registration is gone */
    public function recipient(Delivery $delivery): ?string
    {
        $user = $this->user($delivery);
        if ($user === null || $user->user_email === '') {
            return null;
        }
        if ($this->secretLinks->url($delivery->registration, home_url('/')) === null) {
            $error = new RecipientUnavailable(RecipientUnavailable::NO_REGISTRATION);
            throw $error;
        }

        return $user->user_email;
    }

    public function compose(Delivery $delivery, string $to): MailMessage
    {
        $user = $this->user($delivery) ?? throw new \RuntimeException('The account of the mail is gone.');
        $status = $this->secretLinks->url($delivery->registration, home_url('/'))
            ?? throw new \RuntimeException('The registration of the mail is gone.');
        $event = $this->events->find($delivery->registration->eventId);
        $suffix = $event === null ? '' : ' – ' . $event->name;

        $key = get_password_reset_key($user);
        if ($key instanceof \WP_Error) {
            $error = new \RuntimeException('No password reset key: ' . $key->get_error_code());
            throw $error;
        }
        $link = network_site_url(
            'wp-login.php?action=rp&key=' . rawurlencode($key) . '&login=' . rawurlencode($user->user_login),
            'login'
        );
        $body = self::paragraph(self::SET_PASSWORD_TEXT) . self::link($link, self::SET_PASSWORD_LINK);
        $body .= self::paragraph(self::STATUS_TEXT) . self::link($status, $status);

        return new MailMessage(
            self::SET_PASSWORD_SUBJECT . $suffix,
            $body,
            ['Content-Type: text/html; charset=UTF-8'],
            self::VERSION
        );
    }

    /** The account of a set-password delivery; null for any other delivery, or a user that is gone. */
    private function user(Delivery $delivery): ?\WP_User
    {
        if (
            !self::handles($delivery)
            || $delivery->causeType !== 'account'
            || preg_match('/\A[1-9][0-9]{0,18}\z/D', $delivery->causeKey) !== 1
        ) {
            return null;
        }
        $user = get_userdata((int) $delivery->causeKey);

        return $user instanceof \WP_User ? $user : null;
    }

    private static function paragraph(string $text): string
    {
        return '<p>' . esc_html($text) . '</p>';
    }

    private static function link(string $url, string $text): string
    {
        return '<p><a href="' . esc_url($url) . '">' . esc_html($text) . '</a></p>';
    }
}
