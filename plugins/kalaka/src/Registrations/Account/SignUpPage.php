<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Account;

use Kalaka\Events\Api\AccountMode;
use Kalaka\Events\Api\EventState;
use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Api\SignUp;

/**
 * WordPress's own login and registration pages (`wp-login.php`, `?action=register`) as Kaláka's way in (S15;
 * owner rulings 2026-11-14, 2026-11-17 and 2026-11-18), extended through their hooks. A form's account block
 * opens the login page first (OP5) in a window of its own; it links to the registration page; both name the
 * event and link to each other. Kaláka
 * never handles a password: logging in is `wp_login_url()`, setting the password is WordPress's own
 * `?action=rp` screen, and any WordPress user counts.
 *
 * Two gates:
 * - **The flag** (`$flag`), set on every POST to `action=register` without `loginSocial` (social sign-ups are
 *   their plugin's): it sets the hidden login name (`LoginName`), an opaque nicename, the
 *   `kalaka_unverified` mark, logs the new user in at once (`register_new_user`, then WordPress redirects
 *   to `redirect_to`), and suppresses WordPress's own new-user mails, to the user and to the admin.
 * - **The marker** (`SignUp::MARKER`, a published registration form whose published event has accounts): it
 *   keeps itself across every POST of the login, registration and reset screens as a hidden field, names the
 *   event on the login and registration pages (OP5), and makes WordPress's links and redirects return to the
 *   form: the "Log in" and "Register" links, the registration's own redirect and the login's
 *   (`login_redirect`) through the return page (`returnPage()`, `SignUp::returnUrl()`), which tells the
 *   form's window opener and closes itself, and the "Log in" link after a password is set through the
 *   registration mail's link, which carries the marker (OP1), straight to the form's page. The way back is
 *   always the form's page resolved on the server (`SignUp::page()`), never an address from the request.
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
    /** The user meta of an account Kaláka created whose password was never set: `1`. */
    public const UNVERIFIED = 'kalaka_unverified';

    /** How long a reset link lasts for an unverified account: 7 days. */
    public const EXPIRATION = 7 * DAY_IN_SECONDS;

    /**
     * [UNAPPROVED N29] The return page's script, `%s` the form's page as JSON: it tells the page that opened
     * the window (same origin only) that someone logged in, and closes the window; with no such page (a tab
     * whose opener is gone), or when the window stays open, it goes to the form's page. The message carries
     * nothing: the form reads its new REST nonce itself.
     */
    public const RETURN_SCRIPT = '(function(p){var o=window.opener;try{if(o&&o!==window'
        . '&&o.location.origin===location.origin){o.postMessage({kalaka:"account"},location.origin);'
        . 'window.close();}}catch(e){}setTimeout(function(){location.replace(p);},500);})(%s);';

    /** The registration page's script: the error tied to the e-mail field, then the focus on it. */
    public const FOCUS_SCRIPT = 'window.addEventListener("load",function(){'
        . 'var e=document.getElementById("user_email");if(!e){return;}'
        . 'if(document.getElementById("login_error")){'
        . 'e.setAttribute("aria-invalid","true");e.setAttribute("aria-describedby","login_error");}'
        . 'e.focus();});';

    /** Whether this request is a native sign-up POST (not a social one). */
    private static bool $flag = false;

    /** The account whose first password the current reset sets, or null (`firstPassword()`). */
    private static ?int $firstPassword = null;

    public function __construct(
        private readonly Forms $forms = new Forms(),
        private readonly Events $events = new Events(),
    ) {
    }

    public function register(): void
    {
        add_action('login_form_register', [$this, 'beforeRegister'], 10);
        add_action('register_form', [$this, 'markerField'], 5);
        add_action('login_enqueue_scripts', [$this, 'styles']);
        add_filter('pre_user_nicename', [$this, 'nicename']);
        add_action('user_register', [$this, 'markUnverified'], 10);
        add_filter('wp_send_new_user_notification_to_user', [$this, 'sendWordPressMail']);
        add_filter('wp_send_new_user_notification_to_admin', [$this, 'sendWordPressMail']);
        add_action('register_new_user', [$this, 'logIn'], 20);
        add_filter('login_url', [$this, 'loginUrl'], 10, 2);
        add_filter('register_url', [$this, 'registerUrl']);
        add_filter('registration_redirect', [$this, 'back']);
        add_filter('login_redirect', [$this, 'loginRedirect'], 10, 3);
        add_filter('login_message', [$this, 'message']);
        add_action('login_form', [$this, 'markerField']);
        add_action('login_form_' . SignUp::RETURN_ACTION, [$this, 'returnPage']);
        add_action('resetpass_form', [$this, 'markerField']);
        add_action('password_reset', [self::class, 'firstPassword'], 1);
        add_filter('woocommerce_disable_password_change_notification', [self::class, 'wooCommerceNotification']);
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
        self::$firstPassword = null;
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
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- core's form has no nonce
        $_POST['user_login'] = LoginName::from($email);
    }

    /**
     * `login_form_kalaka_account`: the return page (`SignUp::returnUrl()`), where a login or sign-up from the
     * form's account block lands. It prints no text: its script (`RETURN_SCRIPT`) tells the form's page and
     * closes the window, or goes to the form's page (resolved on the server; the home page without a valid
     * marker or a page that places the form).
     *
     * @internal
     */
    public function returnPage(): void
    {
        $marked = $this->marked();
        $page = ($marked === null ? null : self::page($marked[0])) ?? home_url('/');
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- a constant script; JSON with `<` hexed
        printf(
            '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex">'
                . '<title>%s</title></head><body><script>%s</script>'
                . '<noscript><meta http-equiv="refresh" content="0;url=%s"></noscript></body></html>',
            esc_html(get_bloginfo('name')),
            sprintf(self::RETURN_SCRIPT, (string) wp_json_encode($page, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES)),
            esc_url($page)
        );
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * `login_form`, `register_form` (priority 5) and `resetpass_form`: with a valid marker, the marker kept as a
     * hidden field, since the screens' forms post to an address without the query.
     *
     * @internal
     */
    public function markerField(): void
    {
        $marked = $this->marked();
        if ($marked !== null) {
            printf(
                '<input type="hidden" name="%s" value="%s">',
                esc_attr(SignUp::MARKER),
                esc_attr((string) $marked[0])
            );
        }
    }

    /**
     * `login_message`: with a valid marker, the login page and the registration page name the event and link
     * to each other, keeping the marker (OP5, owner 2026-11-17). No other screen gets a line.
     *
     * @internal
     */
    public function message(mixed $message): string
    {
        $message = is_string($message) ? $message : '';
        $marked = $this->marked();
        $action = self::input('request', 'action') ?? 'login';
        if ($marked === null || !in_array($action, ['login', 'register'], true)) {
            return $message;
        }
        [$formId, , $event] = $marked;
        $return = self::page($formId) ?? '';
        $line = $action === 'register'
            // OP5, approved by the owner 2026-11-17: the registration page.
            ? sprintf(
                '%s <a href="%s">%s</a>.',
                esc_html('Fiókot készítesz, hogy jelentkezhess erre az eseményre: ' . $event . '. Ha már van fiókod,'),
                esc_url(SignUp::url($formId, $return)),
                esc_html('jelentkezz be')
            )
            // OP5, approved by the owner 2026-11-17: the login page.
            : sprintf(
                '%s <a href="%s">%s</a>.',
                esc_html('Jelentkezz be, hogy jelentkezhess erre az eseményre: ' . $event . '. Ha még nincs fiókod,'),
                esc_url(SignUp::registerUrl($formId, $return)),
                esc_html('készíts egyet')
            );

        return $message . '<p class="message kalaka-sign-up-event">' . $line . '</p>';
    }

    /**
     * `login_enqueue_scripts`: on the registration page, the username field and the
     * "confirmation will be emailed" line hidden, and the focus on the e-mail (S15-8, S15-9, owner 2026-11-16).
     * When WordPress shows an error (`#login_error`), the e-mail field is marked invalid and described by it
     * before it takes the focus, so a screen reader reads the error with the field (S15 UX review 9).
     *
     * @internal
     */
    public function styles(): void
    {
        if ((self::input('request', 'action') ?? 'login') !== 'register') {
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
        wp_add_inline_script('kalaka-sign-up', self::FOCUS_SCRIPT);
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
     * `user_register`, priority 10: a native sign-up's account is unverified.
     *
     * @internal
     */
    public function markUnverified(int $userId): void
    {
        if (!self::$flag) {
            return;
        }
        update_user_meta($userId, self::UNVERIFIED, '1');
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
     * `login_url`: with a valid marker, WordPress's "Log in" links (the "already registered" one among them)
     * keep the marker and return to the form through the return page. On the reset screens (OP1) the link
     * only returns to the form's page, without the marker, so the login page shows no event line to someone
     * who has already sent the form. A caller's own redirect is kept.
     *
     * @internal
     */
    public function loginUrl(string $url, mixed $redirect = ''): string
    {
        $marked = $this->marked();
        if ($marked === null) {
            return $url;
        }
        $action = self::input('request', 'action') ?? 'login';
        $reset = in_array($action, ['rp', 'resetpass'], true);
        $return = is_string($redirect) && $redirect !== '' ? null : self::page($marked[0]);
        if ($return !== null) {
            $back = $reset ? $return : SignUp::returnUrl($marked[0], $return);
            $url = add_query_arg('redirect_to', rawurlencode($back), $url);
        }

        return $reset ? $url : add_query_arg(SignUp::MARKER, $marked[0], $url);
    }

    /**
     * `register_url`: with a valid marker, the login page's links to the registration page keep the marker and
     * return to the form through the return page.
     *
     * @internal
     */
    public function registerUrl(mixed $url): string
    {
        $url = is_string($url) ? $url : '';
        $marked = $this->marked();
        if ($marked === null) {
            return $url;
        }
        $return = self::page($marked[0]);
        $url = add_query_arg(SignUp::MARKER, $marked[0], $url);

        return $return === null
            ? $url
            : add_query_arg('redirect_to', rawurlencode(SignUp::returnUrl($marked[0], $return)), $url);
    }

    /**
     * `registration_redirect`: with a valid marker, where the registration page's form returns after the
     * sign-up, the return page for the form's page; WordPress's own otherwise.
     *
     * @internal
     */
    public function back(mixed $redirect): mixed
    {
        return $this->returnTo() ?? $redirect;
    }

    /** [UNAPPROVED N12] With a valid marker and a page placing the form, the return page for it; else null. */
    private function returnTo(): ?string
    {
        $marked = $this->marked();
        $page = $marked === null ? null : self::page($marked[0]);

        return $marked === null || $page === null ? null : SignUp::returnUrl($marked[0], $page);
    }

    /**
     * `login_redirect`: a successful login with a valid marker returns to the form through the return page
     * (OP5, owner 2026-11-18); any other login lands as WordPress (or WooCommerce) sends it.
     *
     * @internal
     */
    public function loginRedirect(mixed $redirect, mixed $requested = '', mixed $user = null): mixed
    {
        unset($requested);

        return ($user instanceof \WP_User ? $this->returnTo() : null) ?? $redirect;
    }

    /**
     * `password_reset`, priority 1 (before `verified()` clears the mark): the first password of a Kaláka
     * sign-up sends the admin no "password changed" mail; a verified account's reset still notifies (S15-7,
     * decided by Master 2026-11-16).
     * - WordPress's reset: core's `wp_password_change_notification` is taken off `after_password_reset` for
     *   this one reset and put back after it.
     * - WooCommerce's reset calls that notification itself after `after_password_reset`, unless
     *   `woocommerce_disable_password_change_notification` says not to (`wooCommerceNotification()`).
     *
     * @internal
     */
    public static function firstPassword(mixed $user): void
    {
        $first = $user instanceof \WP_User && self::isUnverified($user->ID);
        self::$firstPassword = $first ? $user->ID : null;
        $priority = $first ? has_action('after_password_reset', 'wp_password_change_notification') : false;
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

    /**
     * `woocommerce_disable_password_change_notification`: disabled for the first password of a Kaláka
     * sign-up (`firstPassword()`), once.
     *
     * @internal
     */
    public static function wooCommerceNotification(mixed $disabled): bool
    {
        $first = self::$firstPassword !== null;
        self::$firstPassword = null;

        return $first || (bool) $disabled;
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
     * The form, its event's account mode and the event's name a valid marker names: a published registration
     * form whose published event has accounts; null for anything else.
     *
     * @return array{int, AccountMode, string}|null
     */
    private function marked(): ?array
    {
        $formId = SignUp::formIdOf(self::input('request', SignUp::MARKER));
        $form = $formId === null ? null : $this->forms->form($formId);
        if ($form === null || $form->placement !== FormPlacement::Registration) {
            return null;
        }
        $event = $this->events->find($form->eventId);
        if ($event === null || $event->accountMode === AccountMode::Off || $event->state() !== EventState::Published) {
            return null;
        }

        return [$form->id, $event->accountMode, $event->name];
    }

    /**
     * The form's page, resolved on the server (`SignUp::page()`); the request's `redirect_to` only picks among
     * the pages that place the form.
     */
    private static function page(int $formId): ?string
    {
        return SignUp::page($formId, self::input('request', 'redirect_to', false) ?? '');
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
        // phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- see above
        $values = match ($source) {
            'get' => $_GET,
            'post' => $_POST,
            'request' => $_REQUEST,
            'cookie' => $_COOKIE,
            'server' => $_SERVER,
        };
        // phpcs:enable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
        $value = $values[$name] ?? null;
        if (!is_string($value)) {
            return null;
        }
        $value = wp_unslash($value);

        return $text ? sanitize_text_field($value) : esc_url_raw($value);
    }
}
