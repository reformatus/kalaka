<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Accounts;

use Kalaka\Forms\Api\SignUp;
use Kalaka\Registrations\Account\LoginName;
use Kalaka\Registrations\Account\SettingsWarning;
use Kalaka\Registrations\Account\SignUpPage;

/**
 * S15: WordPress's own registration page as the sign-up (owner ruling 2026-11-14), the checks the native
 * sign-up research asks for, where PHP reaches them: the hidden login name, the account logged in at once,
 * the opaque nicename and author 404s, the flag off for social sign-ups, WordPress's mails suppressed,
 * `kalaka_unverified` and its clearing, the 7-day reset link, the marker-gated login URL and skip link, the
 * reminder opt-in, and the settings warning.
 */
final class SignUpPageTest extends AccountsTestCase
{
    /** The form's page: a same-site address. */
    private static function back(): string
    {
        return home_url('/jelentkezes/');
    }

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
        $_POST = ['user_login' => 'amit-a-latogato-irt', 'user_email' => $email, 'redirect_to' => self::back()] + $more;
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

        $result = $this->signUp('teszt.csilla@example.org', [SignUp::MARKER => (string) $form]);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertContains('email_exists', $result->get_error_codes());
        $message = $result->get_error_message('email_exists');
        self::assertStringContainsString('redirect_to=' . rawurlencode(self::back()), $message);
    }

    public function testTheLoginUrlReturnsToTheFormOnlyWithAValidMarkerAndASameSiteAddress(): void
    {
        $form = $this->openForm('mandatory');
        $off = $this->openForm('off');

        self::assertStringNotContainsString('redirect_to', wp_login_url());

        $_REQUEST = [SignUp::MARKER => (string) $form, 'redirect_to' => self::back()];
        self::assertStringContainsString('redirect_to=' . rawurlencode(self::back()), wp_login_url());
        // A redirect the caller gives is kept.
        $given = home_url('/mas/');
        self::assertStringContainsString(rawurlencode($given), wp_login_url($given));

        $_REQUEST = [SignUp::MARKER => (string) $form, 'redirect_to' => 'https://evil.example.com/'];
        self::assertStringNotContainsString('redirect_to', wp_login_url());

        $_REQUEST = [SignUp::MARKER => (string) $off, 'redirect_to' => self::back()];
        self::assertStringNotContainsString('redirect_to', wp_login_url());
    }

    public function testTheMarkerKeepsItselfAndTheSkipLinkShowsOnlyInOptionalMode(): void
    {
        $page = new SignUpPage();
        $optional = $this->openForm('optional');
        $mandatory = $this->openForm('mandatory');

        $_REQUEST = [SignUp::MARKER => (string) $optional, 'redirect_to' => self::back()];
        $html = $this->captured(static fn () => $page->formFields());
        self::assertStringContainsString('name="' . SignUp::MARKER . '" value="' . $optional . '"', $html);
        self::assertStringContainsString('Fiók nélkül folytatom', $html);
        self::assertStringContainsString(esc_url(SignUp::skipUrl($optional, self::back())), $html);
        self::assertStringContainsString('name="' . SignUpPage::REMINDERS_FIELD . '"', $html);

        $_REQUEST = [SignUp::MARKER => (string) $mandatory, 'redirect_to' => self::back()];
        $html = $this->captured(static fn () => $page->formFields());
        self::assertStringContainsString('name="' . SignUp::MARKER . '"', $html);
        self::assertStringNotContainsString('Fiók nélkül folytatom', $html);

        $_REQUEST = [];
        self::assertSame('', $this->captured(static fn () => $page->formFields()));
    }

    public function testTheReminderOptInIsStoredWithTheMarkerOnly(): void
    {
        $form = $this->openForm('mandatory');

        $yes = $this->signUp(
            'teszt.anna@example.org',
            [SignUp::MARKER => (string) $form, SignUpPage::REMINDERS_FIELD => '1']
        );
        SignUpPage::reset();
        $no = $this->signUp('teszt.bela@example.org', [SignUp::MARKER => (string) $form]);
        SignUpPage::reset();
        $plain = $this->signUp('teszt.cecil@example.org');
        SignUpPage::reset();
        $bad = $this->signUp(
            'teszt.dora@example.org',
            [SignUp::MARKER => (string) $form, SignUpPage::REMINDERS_FIELD => 'x']
        );

        self::assertIsInt($yes);
        self::assertIsInt($no);
        self::assertIsInt($plain);
        self::assertSame('1', get_user_meta($yes, SignUpPage::REMINDERS, true));
        self::assertSame('0', get_user_meta($no, SignUpPage::REMINDERS, true));
        self::assertSame('', get_user_meta($plain, SignUpPage::REMINDERS, true));
        self::assertInstanceOf(\WP_Error::class, $bad);
        self::assertContains(SignUpPage::REMINDERS_FIELD, $bad->get_error_codes());
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

    public function testTheSixteenPlusNoticeShowsOnlyInOptionalModeWithTheSkip(): void
    {
        $page = new SignUpPage();
        $notice = 'Fiókot 16 éves kortól készíthetsz. Ha még nem vagy 16 éves, folytasd fiók nélkül.';

        $_REQUEST = [SignUp::MARKER => (string) $this->openForm('optional'), 'redirect_to' => self::back()];
        self::assertStringContainsString($notice, $this->captured(static fn () => $page->formFields()));

        $_REQUEST = [SignUp::MARKER => (string) $this->openForm('mandatory'), 'redirect_to' => self::back()];
        self::assertStringNotContainsString($notice, $this->captured(static fn () => $page->formFields()));
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
