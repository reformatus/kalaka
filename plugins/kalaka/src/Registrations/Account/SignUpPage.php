<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Account;

use Kalaka\Events\Api\AccountMode;
use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Api\SignUp;

/**
 * WordPress's own registration page (`wp-login.php?action=register`) as Kaláka's sign-up (S15; owner ruling
 * 2026-11-14), extended through its hooks. Kaláka never handles a password: logging in is `wp_login_url()`,
 * setting the password is WordPress's own `?action=rp` screen, and any WordPress user counts.
 *
 * Two gates:
 * - **The flag** (`$flag`), set on every POST to `action=register` without `loginSocial` (social sign-ups are
 *   their plugin's): it sets the hidden login name (`LoginName`), an opaque nicename, the
 *   `kalaka_unverified` mark, logs the new user in at once (`register_new_user`, then WordPress redirects
 *   to `redirect_to`), and suppresses WordPress's own new-user mails, to the user and to the admin.
 * - **The marker** (`SignUp::MARKER`, a registration form whose event has accounts): it keeps itself across
 *   the POST as a hidden field, shows the reminder opt-in (stored as `kalaka_draft_reminders`, `1` or `0`,
 *   for S74's reminder mail), puts the skip link on the page in `optional` mode, and makes WordPress's
 *   "Log in" links (the "already registered" one among them) return to the form.
 *
 * `kalaka_unverified` stays until the password is set through a reset link (WordPress's or WooCommerce's
 * reset, both fire `password_reset` and `after_password_reset`). It limits only what Kaláka does with the
 * account (`isUnverified()`); for such an account the reset link lasts 7 days. On every registration page
 * the username field (filled in by the server) and WordPress's "confirmation will be emailed" line are
 * hidden. Author archives of users without published posts are 404s, so no display name leaks by ID.
 *
 * Needs the site settings "Anyone can register" (`users_can_register`) on and the default role
 * `subscriber` (`SettingsWarning`). Multisite is not supported (its registration is `wp-signup.php`).
 */
final class SignUpPage
{
    /** The user meta of an account Kaláka created whose password was never set: `1`. [UNAPPROVED N13] */
    public const UNVERIFIED = 'kalaka_unverified';

    /** The user meta of the reminder opt-in made on the registration page: `1` or `0`. [UNAPPROVED N15] */
    public const REMINDERS = 'kalaka_draft_reminders';

    /** The opt-in's field on the registration page. */
    public const REMINDERS_FIELD = 'kalaka_draft_reminders';

    /** How long a reset link lasts for an unverified account: 7 days. [UNAPPROVED N14] */
    public const EXPIRATION = 7 * DAY_IN_SECONDS;

    /** Whether this request is a native sign-up POST (not a social one). */
    private static bool $flag = false;

    public function __construct(
        private readonly Forms $forms = new Forms(),
        private readonly Events $events = new Events(),
    ) {
    }

    public function register(): void
    {
        add_action('login_form_register', [$this, 'beforeRegister'], 10);
        add_action('register_form', [$this, 'formFields'], 5);
        add_filter('registration_errors', [$this, 'validate'], 20);
        add_action('login_enqueue_scripts', [$this, 'styles']);
        add_filter('pre_user_nicename', [$this, 'nicename']);
        add_action('user_register', [$this, 'markUnverified'], 10);
        add_filter('wp_send_new_user_notification_to_user', [$this, 'sendWordPressMail']);
        add_filter('wp_send_new_user_notification_to_admin', [$this, 'sendWordPressMail']);
        add_action('register_new_user', [$this, 'logIn'], 20);
        add_filter('login_url', [$this, 'loginUrl'], 10, 2);
        add_action('password_reset', [self::class, 'firstPassword'], 1);
        add_action('password_reset', [self::class, 'verified']);
        add_action('after_password_reset', [self::class, 'verified']);
        add_filter('password_reset_expiration', [$this, 'expiration']);
        add_filter('pre_handle_404', [self::class, 'authorArchive'], 10, 2);
    }

    /** Whether the account is one Kaláka created whose password was never set. */
    public static function isUnverified(int $userId): bool
    {
        return get_user_meta($userId, self::UNVERIFIED, true) === '1';
    }

    /** Whether this request is a native sign-up (the flag). */
    public static function flagged(): bool
    {
        return self::$flag;
    }

    /** For tests: clears the flag. */
    public static function reset(): void
    {
        self::$flag = false;
    }

    /**
     * `login_form_register`: on a POST without `loginSocial`, the flag, and the login name from the e-mail
     * before `register_new_user()` reads it.
     *
     * @internal
     */
    public function beforeRegister(): void
    {
        $method = strtoupper(self::input('server', 'REQUEST_METHOD') ?? 'GET');
        if ($method !== 'POST' || self::input('request', 'loginSocial') !== null) {
            return;
        }
        self::$flag = true;
        $email = sanitize_email(self::input('post', 'user_email') ?? '');
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the login name core reads next; WordPress's registration form has no nonce
        $_POST['user_login'] = LoginName::from($email);
    }

    /**
     * `register_form`, priority 5 (above Turnstile and social buttons): with a valid marker, the marker kept
     * as a hidden field (the form's action drops the query), and in `optional` mode the skip link back to
     * the form.
     *
     * @internal
     */
    public function formFields(): void
    {
        $marked = $this->marked();
        if ($marked === null) {
            return;
        }
        [$formId, $mode] = $marked;
        printf('<input type="hidden" name="%s" value="%d">', esc_attr(SignUp::MARKER), (int) $formId);
        $ticked = self::input('post', self::REMINDERS_FIELD) === '1';
        // [UNAPPROVED T5] the reminder opt-in's label.
        printf(
            '<p class="kalaka-sign-up-reminders"><label><input type="checkbox" name="%s" value="1"%s> %s</label></p>',
            esc_attr(self::REMINDERS_FIELD),
            $ticked ? ' checked' : '',
            esc_html('Kérek e-mailes emlékeztetőt, ha félbehagyom a jelentkezést.')
        );
        $return = self::returnUrl();
        if ($mode === AccountMode::Optional && $return !== '') {
            // S15-4 (ii), owner ruling 2026-11-16: the sign-up is for 16 and over, younger people skip; unchecked.
            // [UNAPPROVED T7] the notice.
            printf(
                '<p class="kalaka-sign-up-age">%s</p>',
                esc_html('A regisztráció 16 éves kortól lehetséges. Ha még nem vagy 16 éves, folytasd fiók nélkül.')
            );
            // [UNAPPROVED T2] the skip link.
            printf(
                '<p class="kalaka-sign-up-skip"><a href="%s">%s</a></p>',
                esc_url(SignUp::skipUrl($formId, $return)),
                esc_html('Fiók nélkül folytatom')
            );
        }
    }

    /**
     * `login_enqueue_scripts`: on the registration page, the username field and the "confirmation will be
     * emailed" line hidden, and the focus on the e-mail (S15-8, S15-9, owner 2026-11-16).
     *
     * @internal
     */
    public function styles(): void
    {
        if (self::input('request', 'action') !== 'register') {
            return;
        }
        wp_register_style('kalaka-sign-up', false, [], KALAKA_VERSION);
        wp_enqueue_style('kalaka-sign-up');
        wp_add_inline_style(
            'kalaka-sign-up',
            '#registerform p:has(> #user_login), #registerform label[for="user_login"],'
            . ' #registerform #user_login, #reg_passmail { display: none !important; }'
        );
        wp_register_script('kalaka-sign-up', false, [], KALAKA_VERSION, true);
        wp_enqueue_script('kalaka-sign-up');
        wp_add_inline_script(
            'kalaka-sign-up',
            'window.addEventListener("load",function(){var e=document.getElementById("user_email");if(e){e.focus();}});'
        );
    }

    /**
     * `pre_user_nicename`: an opaque nicename for a native sign-up, so author pages never name the person.
     *
     * @internal
     */
    public function nicename(string $nicename): string
    {
        return self::$flag ? 'u' . bin2hex(random_bytes(6)) : $nicename;
    }

    /**
     * `registration_errors`, priority 20 (after Turnstile and the rest): with a valid marker, the reminder
     * opt-in is either not sent or `1`.
     *
     * @internal
     */
    public function validate(mixed $errors): mixed
    {
        $value = self::input('post', self::REMINDERS_FIELD);
        if ($errors instanceof \WP_Error && $this->marked() !== null && $value !== null && $value !== '1') {
            // [UNAPPROVED T5] the error of a malformed opt-in (only a tampered request sends one).
            $errors->add(self::REMINDERS_FIELD, esc_html('Az emlékeztetőre vonatkozó választás érvénytelen.'));
        }

        return $errors;
    }

    /**
     * `user_register`, priority 10: a native sign-up's account is unverified, and with a valid marker its
     * reminder opt-in is stored.
     *
     * @internal
     */
    public function markUnverified(int $userId): void
    {
        if (!self::$flag) {
            return;
        }
        update_user_meta($userId, self::UNVERIFIED, '1');
        if ($this->marked() !== null) {
            $ticked = self::input('post', self::REMINDERS_FIELD) === '1';
            update_user_meta($userId, self::REMINDERS, $ticked ? '1' : '0');
        }
    }

    /**
     * `wp_send_new_user_notification_to_user` and `…_to_admin`: none for a native sign-up. The set-password
     * link travels in the registration's own mail; admins get none (S15-7, owner 2026-11-16).
     *
     * @internal
     */
    public function sendWordPressMail(mixed $send): bool
    {
        return self::$flag ? false : (bool) $send;
    }

    /**
     * `register_new_user`, priority 20: the new account is logged in at once; WordPress then redirects to the
     * posted `redirect_to`.
     *
     * @internal
     */
    public function logIn(int $userId): void
    {
        if (!self::$flag) {
            return;
        }
        wp_set_auth_cookie($userId, false);
        wp_set_current_user($userId);
    }

    /**
     * `login_url`: with a valid marker, WordPress's "Log in" links return to the form (`redirect_to`,
     * same-site only).
     *
     * @internal
     */
    public function loginUrl(string $url, mixed $redirect = ''): string
    {
        if ((is_string($redirect) && $redirect !== '') || $this->marked() === null) {
            return $url;
        }
        $return = self::returnUrl();

        return $return === '' ? $url : add_query_arg('redirect_to', rawurlencode($return), $url);
    }

    /**
     * `password_reset`, priority 1 (before `verified()` clears the mark): the first password of a Kaláka
     * sign-up sends the admin no "password changed" mail. Core's `wp_password_change_notification` is taken off
     * `after_password_reset` for this one reset and put back after it; a verified account's reset still
     * notifies. WooCommerce's reset fires the same two actions (S15-7, decided by Master 2026-11-16).
     *
     * @internal
     */
    public static function firstPassword(mixed $user): void
    {
        if (!$user instanceof \WP_User || !self::isUnverified($user->ID)) {
            return;
        }
        $priority = has_action('after_password_reset', 'wp_password_change_notification');
        if ($priority === false) {
            return;
        }
        remove_action('after_password_reset', 'wp_password_change_notification', $priority);
        $restore = static function () use (&$restore, $priority): void {
            add_action('after_password_reset', 'wp_password_change_notification', $priority);
            remove_action('after_password_reset', $restore, PHP_INT_MAX);
        };
        add_action('after_password_reset', $restore, PHP_INT_MAX);
    }

    /** @internal `password_reset` and `after_password_reset`: the password was set through a reset link. */
    public static function verified(mixed $user): void
    {
        if ($user instanceof \WP_User) {
            delete_user_meta($user->ID, self::UNVERIFIED);
        }
    }

    /**
     * `password_reset_expiration`: 7 days when the reset link being checked is an unverified account's. The
     * filter is given no user, so the login comes from the request, as WordPress's and WooCommerce's reset
     * screens carry it (the `login` query argument, the posted `reset_login`, or the reset cookie).
     * [UNAPPROVED N14]
     *
     * @internal
     */
    public function expiration(mixed $seconds): int
    {
        $login = self::resetLogin();
        $user = $login === null ? false : get_user_by('login', $login);

        return $user instanceof \WP_User && self::isUnverified($user->ID) ? self::EXPIRATION : (int) $seconds;
    }

    /**
     * `pre_handle_404`: an author archive of a user without published posts is a 404, so `?author=N` names
     * nobody.
     *
     * @internal
     */
    public static function authorArchive(mixed $preempt, mixed $query): mixed
    {
        if ($preempt !== false || !$query instanceof \WP_Query || !$query->is_author()) {
            return $preempt;
        }
        $author = $query->get_queried_object();
        $types = get_post_types(['public' => true]);
        if (!$author instanceof \WP_User || (int) count_user_posts($author->ID, $types, true) > 0) {
            return $preempt;
        }
        $query->set_404();
        status_header(404);
        nocache_headers();

        return true;
    }

    /**
     * The form and its event's account mode a valid marker names: a registration form whose event has
     * accounts; null for anything else.
     *
     * @return array{int, AccountMode}|null
     */
    private function marked(): ?array
    {
        $formId = SignUp::formIdOf(self::input('request', SignUp::MARKER));
        $form = $formId === null ? null : $this->forms->form($formId);
        if ($form === null || $form->placement !== FormPlacement::Registration) {
            return null;
        }
        $event = $this->events->find($form->eventId);
        if ($event === null || $event->accountMode === AccountMode::Off) {
            return null;
        }

        return [$form->id, $event->accountMode];
    }

    /** The request's `redirect_to`, when it is a same-site address; empty otherwise. */
    private static function returnUrl(): string
    {
        $raw = self::input('request', 'redirect_to', false) ?? '';

        return $raw === '' ? '' : wp_validate_redirect($raw, '');
    }

    /** The login of the reset link the request checks, as WordPress's and WooCommerce's screens carry it. */
    private static function resetLogin(): ?string
    {
        $login = self::input('get', 'login') ?? self::input('post', 'reset_login');
        if ($login !== null) {
            return $login;
        }
        // WordPress's reset cookie, `wp-resetpass-` and the site's cookie hash (`COOKIEHASH`).
        $hash = defined('COOKIEHASH') ? (string) constant('COOKIEHASH') : md5((string) get_site_option('siteurl'));
        $cookie = self::input('cookie', 'wp-resetpass-' . $hash);

        return $cookie !== null && str_contains($cookie, ':') ? explode(':', $cookie, 2)[0] : null;
    }

    /**
     * One request value as a string, unslashed and sanitized as text (or as a URL with `$text` false); null
     * when it is missing or not a string. WordPress's own registration and reset forms carry no nonce: Kaláka
     * reads the same fields core reads, and checks each where it is used.
     *
     * @param 'get'|'post'|'request'|'cookie'|'server' $source
     */
    private static function input(string $source, string $name, bool $text = true): ?string
    {
        // phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see above; sanitized below
        $values = match ($source) {
            'get' => $_GET,
            'post' => $_POST,
            'request' => $_REQUEST,
            'cookie' => $_COOKIE,
            'server' => $_SERVER,
        };
        // phpcs:enable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $value = $values[$name] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $value = wp_unslash($value);

        return $text ? sanitize_text_field($value) : esc_url_raw($value);
    }
}
