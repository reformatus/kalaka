<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Accounts;

use Kalaka\Forms\Api\SignUp;
use Kalaka\Registrations\Account\LoginName;
use Kalaka\Registrations\Account\SettingsWarning;
use Kalaka\Registrations\Account\SignUpPage;
use Kalaka\Registrations\Api\Accounts;

/**
 * S15: WordPress's own registration page as the sign-up (owner ruling 2026-11-14), the checks the native
 * sign-up research asks for, where PHP reaches them: the hidden login name, the account logged in at once,
 * the opaque nicename and author 404s, the flag off for social sign-ups, WordPress's mails suppressed,
 * `kalaka_unverified` and its clearing, the 7-day reset link, the marker-gated login URL and skip block,
 * N27's sign-up form read, and the settings warning.
 */
final class SignUpPageTest extends AccountsTestCase
{
    /**
     * A POST to `wp-login.php?action=register` as core handles it: the hook, then `register_new_user()` with
     * the posted login and e-mail.
     *
     * @param array<string, string> $more
     */
    private function signUp(string $email, array $more = []): int|\WP_Error
    {
        // phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- a test
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $more
            + ['user_login' => 'amit-a-latogato-irt', 'user_email' => $email, 'redirect_to' => home_url('/')];
        $_REQUEST = $_POST;
        do_action('login_form_register');

        return register_new_user((string) $_POST['user_login'], (string) $_POST['user_email']);
        // phpcs:enable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
    }

    private function captured(callable $print): string
    {
        ob_start();
        $print();

        return (string) ob_get_clean();
    }

    public function testTheLoginNameIsAsciiFreeValidAndNeverEmpty(): void
    {
        self::factory()->user->create(['user_login' => 'teszt.anna', 'user_email' => 'other@example.org']);
        add_filter('illegal_user_logins', $illegal = static fn (): array => ['tilos']);

        self::assertSame('teszt.anna2', LoginName::from('Teszt.Anna@example.org'));
        self::assertSame('tilos2', LoginName::from('tilos@example.org'));
        self::assertSame('annatabor', LoginName::from('anna+tabor@example.org'));
        self::assertSame('rpd', LoginName::from('Árpád@example.org'));
        self::assertSame(LoginName::FALLBACK, LoginName::from(''));
        self::assertSame(LoginName::FALLBACK, LoginName::from('ő@example.org'));
        $long = LoginName::from(str_repeat('a', 80) . '@example.org');
        self::assertSame(50, strlen($long));
        foreach (['Teszt.Anna@example.org', 'tilos@example.org', '', str_repeat('a', 80) . '@x.org'] as $email) {
            $login = LoginName::from($email);
            self::assertTrue(validate_username($login), $login);
            self::assertLessThanOrEqual(60, strlen($login));
            self::assertMatchesRegularExpression('/\A[a-z0-9._-]+\z/', $login);
        }
        remove_filter('illegal_user_logins', $illegal);
    }

    public function testASignUpGetsTheHiddenLoginIsLoggedInAndUnverifiedWithAnOpaqueNicename(): void
    {
        $id = $this->signUp('teszt.anna@example.org');

        self::assertIsInt($id);
        $user = get_userdata($id);
        self::assertInstanceOf(\WP_User::class, $user);
        self::assertSame('teszt.anna', $user->user_login);
        self::assertSame(['subscriber'], array_values($user->roles));
        self::assertMatchesRegularExpression('/\Au[0-9a-f]{12}\z/', $user->user_nicename);
        self::assertSame($id, get_current_user_id());
        self::assertTrue(SignUpPage::isUnverified($id));
        // WordPress's own mails (to the user, to the admin) are not sent; nothing is mailed in the request.
        self::assertSame([], $this->mails);
    }

    public function testASocialSignUpIsNotFlagged(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification -- a test sets the request
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['user_email' => 'teszt.bence@example.org'];
        $_REQUEST = $_POST + ['loginSocial' => 'google'];

        do_action('login_form_register');

        self::assertFalse(SignUpPage::flagged());
        self::assertArrayNotHasKey('user_login', $_POST);
        // phpcs:enable WordPress.Security.NonceVerification
        $id = self::factory()->user->create(['user_email' => 'teszt.bence@example.org']);
        self::assertFalse(SignUpPage::isUnverified($id));
    }

    public function testAGetIsNotFlagged(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        do_action('login_form_register');

        self::assertFalse(SignUpPage::flagged());
    }

    public function testAnExistingEmailGetsWordPresssErrorWithALoginLinkBackToTheForm(): void
    {
        $form = $this->openForm('mandatory');
        $this->verifiedUser('teszt.csilla@example.org');

        $back = $this->placed($form);

        $result = $this->signUp('teszt.csilla@example.org', [SignUp::MARKER => (string) $form, 'redirect_to' => $back]);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertContains('email_exists', $result->get_error_codes());
        $message = $result->get_error_message('email_exists');
        self::assertStringContainsString('redirect_to=' . rawurlencode($back), $message);
    }

    public function testTheLoginUrlReturnsToTheFormsOwnPageOnlyWithAValidMarker(): void
    {
        $form = $this->openForm('mandatory');
        $off = $this->openForm('off');
        $unplaced = $this->openForm('mandatory');
        $back = $this->placed($form);
        $this->placed($off);

        self::assertStringNotContainsString('redirect_to', wp_login_url());

        $_REQUEST = [SignUp::MARKER => (string) $form];
        $url = wp_login_url();
        self::assertStringContainsString('redirect_to=' . rawurlencode($back), $url);
        self::assertStringContainsString(SignUp::MARKER . '=' . $form, $url);
        // A redirect the caller gives is kept.
        $given = home_url('/mas/');
        self::assertStringContainsString(rawurlencode($given), wp_login_url($given));

        // N25: a requested address that is not a page placing the form is ignored; the form's page wins.
        $_REQUEST = [SignUp::MARKER => (string) $form, 'redirect_to' => 'https://evil.example.com/'];
        self::assertStringContainsString('redirect_to=' . rawurlencode($back), wp_login_url());
        self::assertStringNotContainsString('evil.example.com', wp_login_url());
        $_REQUEST = [SignUp::MARKER => (string) $form, 'redirect_to' => home_url('/mas/')];
        self::assertStringContainsString('redirect_to=' . rawurlencode($back), wp_login_url());

        // A second page placing the form: the request picks it.
        $second = self::factory()->post->create([
            'post_type' => 'page',
            'post_content' => '[kalaka_form id="' . $form . '"]',
        ]);
        $_REQUEST = [SignUp::MARKER => (string) $form, 'redirect_to' => (string) get_permalink($second)];
        $picked = (string) get_permalink($second);
        self::assertStringContainsString('redirect_to=' . rawurlencode($picked), wp_login_url());

        // No page places the form: WordPress's own login URL.
        $_REQUEST = [SignUp::MARKER => (string) $unplaced];
        self::assertStringNotContainsString('redirect_to', wp_login_url());

        $_REQUEST = [SignUp::MARKER => (string) $off];
        self::assertSame(wp_login_url(), site_url('wp-login.php', 'login'));
    }

    public function testTheMarkerKeepsItselfAsAHiddenField(): void
    {
        $page = new SignUpPage();
        $optional = $this->openForm('optional');
        $mandatory = $this->openForm('mandatory');
        $this->placed($optional);
        $this->placed($mandatory);

        foreach ([$optional, $mandatory] as $form) {
            $_REQUEST = [SignUp::MARKER => (string) $form];
            $html = $this->captured($page->markerField(...));
            self::assertSame('<input type="hidden" name="' . SignUp::MARKER . '" value="' . $form . '">', $html);
            self::assertStringNotContainsString('kalaka_draft_reminders', $html, 'The reminder plugin\'s.');
        }

        $_REQUEST = [];
        self::assertSame('', $this->captured($page->markerField(...)));
    }

    public function testTheSkipBlockShowsBelowTheLoginAndRegistrationBoxesInOptionalModeOnly(): void
    {
        $page = new SignUpPage();
        $optional = $this->openForm('optional');
        $mandatory = $this->openForm('mandatory');
        $skip = esc_url(SignUp::skipUrl($optional, $this->placed($optional)));
        $this->placed($mandatory);
        $notice = 'Fiókot 16 éves kortól készíthetsz. Ha még nem vagy 16 éves, folytasd fiók nélkül.';
        $note = 'Fiók nélkül később csak az e-mailben kapott linkkel éred el a jelentkezésedet.';
        $button = '<a class="button button-large" href="' . $skip . '">Fiók nélkül folytatom</a>';

        foreach (['login', 'register'] as $action) {
            $_REQUEST = ['action' => $action, SignUp::MARKER => (string) $optional];
            $html = $this->captured($page->skip(...));
            self::assertStringStartsWith('<div id="kalaka-sign-up-skip">', $html, $action);
            self::assertLessThan(strpos($html, $note), strpos($html, $notice), $action . ': the notice first');
            self::assertLessThan(strpos($html, $button), strpos($html, $note), $action . ': the texts first');
            self::assertStringNotContainsString('name="' . SignUp::MARKER . '"', $html, 'outside the form');

            $_REQUEST = ['action' => $action, SignUp::MARKER => (string) $mandatory];
            self::assertSame('', $this->captured($page->skip(...)), $action);
        }
        self::assertStringNotContainsString($notice, $this->captured($page->markerField(...)), 'not in the box');

        foreach (['lostpassword', 'rp', 'resetpass', 'checkemail'] as $action) {
            $_REQUEST = ['action' => $action, SignUp::MARKER => (string) $optional];
            self::assertSame('', $this->captured($page->skip(...)), $action);
        }

        $_REQUEST = [SignUp::MARKER => (string) $optional];
        self::assertStringContainsString($button, $this->captured($page->skip(...)), 'login without an action');
        $_REQUEST = ['action' => 'login'];
        self::assertSame('', $this->captured($page->skip(...)));
    }

    public function testTheSkipBlockIsPlacedUnderTheBoxOnTheLoginAndRegistrationPagesOnly(): void
    {
        foreach (['login' => false, 'register' => true, 'rp' => null] as $action => $register) {
            wp_deregister_style('kalaka-sign-up');
            wp_deregister_script('kalaka-sign-up');
            $_REQUEST = ['action' => $action];
            (new SignUpPage())->styles();
            if ($register === null) {
                self::assertFalse(wp_style_is('kalaka-sign-up', 'registered'), $action);
                continue;
            }
            $css = implode('', (array) wp_styles()->get_data('kalaka-sign-up', 'after'));
            $js = implode('', (array) wp_scripts()->get_data('kalaka-sign-up', 'after'));
            self::assertStringContainsString(SignUpPage::PLACEMENT, $css, $action);
            self::assertStringContainsString(SignUpPage::MOVE_SCRIPT, $js, $action);
            self::assertSame($register, str_contains($css, '#reg_passmail'), $action);
            self::assertSame($register, str_contains($js, SignUpPage::FOCUS_SCRIPT), $action);
        }
    }

    public function testTheLoginAndRegistrationPagesNameTheEventAndLinkToEachOther(): void
    {
        $page = new SignUpPage();
        $form = $this->openForm('mandatory');
        $back = $this->placed($form);

        $_REQUEST = ['action' => 'login', SignUp::MARKER => (string) $form];
        $login = $page->message('');
        self::assertStringContainsString(
            'Jelentkezz be, hogy jelentkezhess erre az eseményre: Nyári tábor. Ha még nincs fiókod, <a href="'
            . esc_url(SignUp::registerUrl($form, $back)) . '">készíts egyet</a>.',
            $login
        );
        self::assertStringContainsString(SignUp::MARKER . '=' . $form, SignUp::registerUrl($form, $back));

        $_REQUEST = ['action' => 'register', SignUp::MARKER => (string) $form];
        $register = $page->message('');
        self::assertStringContainsString(
            'Fiókot készítesz, hogy jelentkezhess erre az eseményre: Nyári tábor. Ha már van fiókod, <a href="'
            . esc_url(SignUp::url($form, $back)) . '">jelentkezz be</a>.',
            $register
        );
        self::assertStringContainsString(SignUp::MARKER . '=' . $form, SignUp::url($form, $back));

        // The event's name is escaped.
        global $wpdb;
        $event = $this->forms->form($form)?->eventId;
        $wpdb->update($wpdb->prefix . 'kalaka_events', ['name' => 'Tábor <b>&</b>'], ['id' => $event]);
        \Kalaka\Foundation\ObjectCache::clear();
        self::assertStringContainsString('eseményre: Tábor &lt;b&gt;&amp;&lt;/b&gt;. Ha már', $page->message(''));

        // Other screens, and no marker: WordPress's own message.
        $_REQUEST = ['action' => 'lostpassword', SignUp::MARKER => (string) $form];
        self::assertSame('x', $page->message('x'));
        $_REQUEST = ['action' => 'login'];
        self::assertSame('x', $page->message('x'));
    }

    public function testALoginWithAValidMarkerReturnsToTheFormsPage(): void
    {
        $form = $this->openForm('mandatory');
        $back = $this->placed($form);
        $off = $this->openForm('off');
        $this->placed($off);
        $additional = $this->openForm('mandatory', 'additional');
        $this->placed($additional);
        // A form of an event that is not published (a draft).
        $draftEvent = $this->create('Téli tábor');
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_events', ['account_mode' => 'mandatory'], ['id' => $draftEvent]);
        \Kalaka\Foundation\ObjectCache::clear();
        $draft = $this->insertForm(
            $this->slotFields(),
            ['kalaka_event_id' => $draftEvent, 'kalaka_placement' => 'registration']
        );
        $this->placed($draft);
        $user = get_userdata($this->verifiedUser());
        $admin = admin_url();

        // OP1: with the marker, back to the form, whatever the request asked for.
        $_REQUEST = [SignUp::MARKER => (string) $form];
        self::assertSame($back, apply_filters('login_redirect', $admin, '', $user));
        $_REQUEST = [SignUp::MARKER => (string) $form, 'redirect_to' => 'https://evil.example.com/'];
        $evil = 'https://evil.example.com/';
        self::assertSame($back, apply_filters('login_redirect', $evil, $evil, $user));
        // The registration's own redirect too.
        self::assertSame($back, apply_filters('registration_redirect', $admin, new \WP_Error()));

        // Without a valid marker, or a failed login, the login lands as WordPress sends it.
        $requests = [
            [],
            [SignUp::MARKER => 'abc'],
            [SignUp::MARKER => '999999'],
            [SignUp::MARKER => (string) $off],
            [SignUp::MARKER => (string) $additional],
            [SignUp::MARKER => (string) $draft],
        ];
        foreach ($requests as $request) {
            $_REQUEST = $request;
            self::assertSame($admin, apply_filters('login_redirect', $admin, '', $user));
        }
        $_REQUEST = [SignUp::MARKER => (string) $form];
        self::assertSame($admin, apply_filters('login_redirect', $admin, '', new \WP_Error()));
    }

    public function testAfterTheResetTheLoginLinkReturnsToTheFormWithoutTheEventLine(): void
    {
        $form = $this->openForm('mandatory');
        $back = $this->placed($form);
        $page = new SignUpPage();

        foreach (['rp', 'resetpass'] as $action) {
            $_REQUEST = ['action' => $action, SignUp::MARKER => (string) $form];
            $url = wp_login_url();
            self::assertStringContainsString('redirect_to=' . rawurlencode($back), $url, $action);
            self::assertStringNotContainsString(SignUp::MARKER, $url, $action);
            self::assertStringContainsString(
                'name="' . SignUp::MARKER . '" value="' . $form . '"',
                $this->captured($page->markerField(...))
            );
            self::assertSame('', $page->message(''));
        }
    }

    /** N27: an extension asks whether a WordPress sign-up request is Kaláka's, and for which form. */
    public function testAnExtensionLearnsTheSignUpsFormFromTheMarkerOnly(): void
    {
        $form = $this->openForm('optional');
        $off = $this->openForm('off');
        $accounts = new Accounts();

        $_REQUEST = [SignUp::MARKER => (string) $form];
        self::assertSame($form, $accounts->signUpForm());
        $_REQUEST = [SignUp::MARKER => (string) $off];
        self::assertNull($accounts->signUpForm());
        $_REQUEST = [];
        self::assertNull($accounts->signUpForm());
    }

    public function testThePasswordResetClearsTheMarkWordPressAndWooCommerceAlike(): void
    {
        $first = $this->unverifiedUser('teszt.anna@example.org');
        $second = $this->unverifiedUser('teszt.bela@example.org');

        reset_password(get_userdata($first), 'Teszt-jelszo-2026!');
        // WooCommerce's My Account reset fires the same two actions.
        do_action('password_reset', get_userdata($second), 'Teszt-jelszo-2026!');

        self::assertFalse(SignUpPage::isUnverified($first));
        self::assertFalse(SignUpPage::isUnverified($second));
    }

    public function testTheFirstPasswordOfASignUpSendsTheAdminNoMailALaterResetDoes(): void
    {
        update_option('admin_email', 'teszt.admin@example.org');
        $user = get_userdata($this->unverifiedUser('teszt.anna@example.org'));
        $admin = static fn (array $mail): bool => $mail['to'] === 'teszt.admin@example.org';

        reset_password($user, 'Teszt-jelszo-2026!');

        self::assertSame([], array_values(array_filter($this->mails, $admin)));
        self::assertFalse(SignUpPage::isUnverified($user->ID));
        // Core's notification is back on its hook for the next reset.
        self::assertNotFalse(has_action('after_password_reset', 'wp_password_change_notification'));

        reset_password(get_userdata($user->ID), 'Masik-jelszo-2026!');

        self::assertCount(1, array_values(array_filter($this->mails, $admin)));
    }

    public function testWooCommercesResetSendsTheAdminNoMailOnTheFirstPasswordALaterOneDoes(): void
    {
        update_option('admin_email', 'teszt.admin@example.org');
        $user = get_userdata($this->unverifiedUser('teszt.anna@example.org'));
        $admin = static fn (array $mail): bool => $mail['to'] === 'teszt.admin@example.org';

        self::wooCommerceReset($user, 'Teszt-jelszo-2026!');

        self::assertSame([], array_values(array_filter($this->mails, $admin)));
        self::assertFalse(SignUpPage::isUnverified($user->ID));
        self::assertNotFalse(has_action('after_password_reset', 'wp_password_change_notification'));

        self::wooCommerceReset(get_userdata($user->ID), 'Masik-jelszo-2026!');

        self::assertCount(1, array_values(array_filter($this->mails, $admin)));
    }

    /**
     * WooCommerce's `WC_Shortcode_My_Account::reset_password()` (11.3), step by step: WooCommerce is not
     * loaded in these tests.
     */
    private static function wooCommerceReset(\WP_User $user, string $password): void
    {
        do_action('password_reset', $user, $password);
        wp_set_password($password, $user->ID);
        $priority = has_action('after_password_reset', 'wp_password_change_notification');
        if ($priority !== false) {
            remove_action('after_password_reset', 'wp_password_change_notification', $priority);
        }
        try {
            do_action('after_password_reset', $user, $password);
        } finally {
            if ($priority !== false) {
                add_action('after_password_reset', 'wp_password_change_notification', $priority);
            }
        }
        if (!apply_filters('woocommerce_disable_password_change_notification', false)) {
            wp_password_change_notification($user);
        }
    }

    public function testTheRegistrationPageTiesWordPresssErrorToTheEmailField(): void
    {
        $_REQUEST = ['action' => 'register'];

        (new SignUpPage())->styles();

        $after = implode('', (array) wp_scripts()->get_data('kalaka-sign-up', 'after'));
        self::assertStringContainsString(SignUpPage::FOCUS_SCRIPT, $after);
        foreach (['login_error', 'aria-invalid', 'aria-describedby', 'focus()'] as $part) {
            self::assertStringContainsString($part, SignUpPage::FOCUS_SCRIPT);
        }
    }

    public function testTheResetLinkLastsSevenDaysOnlyForAnUnverifiedAccount(): void
    {
        $unverified = get_userdata($this->unverifiedUser('teszt.anna@example.org'));
        $verified = get_userdata($this->verifiedUser('teszt.csilla@example.org'));

        $_GET = ['login' => $unverified->user_login];
        self::assertSame(SignUpPage::EXPIRATION, apply_filters('password_reset_expiration', DAY_IN_SECONDS));
        $_GET = ['login' => $verified->user_login];
        self::assertSame(DAY_IN_SECONDS, apply_filters('password_reset_expiration', DAY_IN_SECONDS));
        $_GET = [];
        self::assertSame(DAY_IN_SECONDS, apply_filters('password_reset_expiration', DAY_IN_SECONDS));

        // A key made 3 days ago still opens WordPress's "set new password" screen for the unverified account.
        $key = get_password_reset_key($unverified);
        self::assertIsString($key);
        global $wpdb;
        $stored = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT user_activation_key FROM {$wpdb->users} WHERE ID = %d",
            $unverified->ID
        ));
        [, $hash] = explode(':', $stored, 2);
        $threeDaysAgo = (time() - 3 * DAY_IN_SECONDS) . ':' . $hash;
        $wpdb->update($wpdb->users, ['user_activation_key' => $threeDaysAgo], ['ID' => $unverified->ID]);
        clean_user_cache($unverified->ID);
        $_GET = ['login' => $unverified->user_login];
        self::assertInstanceOf(\WP_User::class, check_password_reset_key($key, $unverified->user_login));
    }

    public function testAuthorArchivesOfUsersWithoutPostsAre404s(): void
    {
        $user = $this->unverifiedUser();
        $writer = self::factory()->user->create(['role' => 'author']);
        self::factory()->post->create(['post_author' => $writer, 'post_status' => 'publish']);

        $this->go_to('/?author=' . $user);
        self::assertTrue(is_404());

        $this->go_to('/?author=' . $writer);
        self::assertFalse(is_404());
    }

    public function testTheSettingsWarningNamesWrongSettingsOnlyWhileAnEventHasAccounts(): void
    {
        update_option('users_can_register', '1');
        update_option('default_role', 'subscriber');
        $this->openForm('mandatory');
        self::assertNull((new SettingsWarning())->check());

        update_option('users_can_register', '0');
        update_option('default_role', 'author');
        $shown = (new SettingsWarning())->check();
        self::assertNotNull($shown);
        self::assertSame("users_can_register is off\ndefault_role is not subscriber", $shown->details);

        global $wpdb;
        $wpdb->query("UPDATE {$wpdb->prefix}kalaka_events SET account_mode = 'off'");
        \Kalaka\Foundation\ObjectCache::clear();
        self::assertNull((new SettingsWarning())->check());
    }
}
