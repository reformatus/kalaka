<?php

declare(strict_types=1);

namespace Kalaka\Mail;

use Kalaka\Events\Api\Events;
use Kalaka\Registrations\Api\AttachLinks;
use Kalaka\Registrations\Api\SecretLinks;

/**
 * The composer of the two account mails (S15), with plain built-in content beside the action-mail
 * catalogue (S33's `Body\ActionMailComposer` hands them here; the bodies are re-asked in S33/S34). Both are action mails queued by the submit, with the cause
 * `account` and the WordPress user ID as its key (`Registrations\Account\AccountOutcome`), and go to that
 * account's current address:
 *
 * - `account_set_password`: the link to WordPress's own "set new password" screen
 *   (`wp-login.php?action=rp`) with a fresh reset key, made here at send time so a wait in the queue does not
 *   use up its lifetime; setting the password activates the account and is the check of the address;
 * - `account_attach`: the attach link (`Registrations\Api\AttachLinks`), which attaches the registration to
 *   the account once its holder is logged in.
 *
 * Both carry the registration's secret link, on the site's home page (re-asked in S33/S34). Any other
 * delivery has no recipient here.
 */
final class AccountMailComposer implements MailComposer
{
    public const SET_PASSWORD = 'account_set_password';

    public const ATTACH = 'account_attach';

    /** The version of the built-in bodies. */
    public const VERSION = 'kalaka-account-1';

    public const SET_PASSWORD_SUBJECT = 'Állítsd be a jelszavadat';

    public const SET_PASSWORD_TEXT = 'A jelentkezésedkor fiókot hoztál létre. A fiókod akkor lesz használható, '
        . 'ha beállítod a jelszavadat ezen a linken:';

    public const SET_PASSWORD_LINK = 'Jelszó beállítása';

    public const ATTACH_SUBJECT = 'Jelentkezés hozzáadása a fiókodhoz';

    public const ATTACH_TEXT = 'Ezzel az e-mail-címmel jelentkeztek egy eseményre, és a címhez már tartozik '
        . 'fiók. Ha a jelentkezést hozzá szeretnéd adni a fiókodhoz, nyisd meg ezt a linket, és jelentkezz be:';

    public const ATTACH_LINK = 'Jelentkezés hozzáadása a fiókodhoz';

    public const ATTACH_NOT_YOU = 'Ha nem te jelentkeztél, ezt a levelet figyelmen kívül hagyhatod.';

    public const STATUS_TEXT = 'A jelentkezésed állapotát ezen a linken nézheted meg:';

    public function __construct(
        private readonly Events $events = new Events(),
        private readonly SecretLinks $secretLinks = new SecretLinks(),
        private readonly AttachLinks $attachLinks = new AttachLinks(),
    ) {
    }

    /** Whether the delivery is one of the two account mails. */
    public static function handles(Delivery $delivery): bool
    {
        return in_array($delivery->mail, [self::SET_PASSWORD, self::ATTACH], true);
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
        $home = home_url('/');
        $status = $this->secretLinks->url($delivery->registration, $home)
            ?? throw new \RuntimeException('The registration of the mail is gone.');
        $event = $this->events->find($delivery->registration->eventId);
        $suffix = $event === null ? '' : ' – ' . $event->name;

        if ($delivery->mail === self::SET_PASSWORD) {
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
            $subject = self::SET_PASSWORD_SUBJECT;
        } else {
            $link = $this->attachLinks->url($delivery->registration, $user->ID, $home)
                ?? throw new \RuntimeException('The registration of the mail is gone.');
            $body = self::paragraph(self::ATTACH_TEXT) . self::link($link, self::ATTACH_LINK)
                . self::paragraph(self::ATTACH_NOT_YOU);
            $subject = self::ATTACH_SUBJECT;
        }
        $body .= self::paragraph(self::STATUS_TEXT) . self::link($status, $status);

        return new MailMessage(
            $subject . $suffix,
            $body,
            ['Content-Type: text/html; charset=UTF-8'],
            self::VERSION
        );
    }

    /** The account of a delivery of one of the two mails; null for any other delivery, or a user that is gone. */
    private function user(Delivery $delivery): ?\WP_User
    {
        if (
            !in_array($delivery->mail, [self::SET_PASSWORD, self::ATTACH], true)
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
