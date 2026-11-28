<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Accounts;

use Kalaka\Forms\Api\AccessRefusal;
use Kalaka\Forms\Api\AccountStep;
use Kalaka\Forms\Api\FormRefused;
use Kalaka\Forms\Api\SignUp;
use Kalaka\Forms\Engine\AccountMarkup;
use Kalaka\Forms\Engine\FormPage;
use Kalaka\Forms\Engine\FormPageView;
use Kalaka\Forms\EventFormHost;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\ObjectCache;

/**
 * S15: the event's account mode on its forms and their submits. Off changes nothing; in mandatory mode
 * someone not logged in sees the whole form and its account block but cannot submit; in optional mode they
 * may send without an account; a logged-in person owns the registration.
 */
final class AccountModesTest extends AccountsTestCase
{
    public function testOffModeChangesNothingLoggedInOrNot(): void
    {
        $form = $this->openForm('off');
        $user = $this->unverifiedUser();

        $guest = $this->submit($form);
        $loggedIn = $this->submit(
            $form,
            new AccountActor($user),
            ['name' => 'Teszt Anna', 'email' => 'teszt.anna2@example.org']
        );

        self::assertNull($this->ownerOf($guest));
        self::assertNull($this->ownerOf($loggedIn));
        self::assertSame([], $this->accountMails());
        self::assertNull((new EventFormHost())->accountStep($this->readForm($form)));
        wp_set_current_user($user);
        $view = (new FormPage(new EventFormHost()))->open($form);
        self::assertNull($view->refusal);
        self::assertStringNotContainsString('teszt.anna@example.org', $view->content);
    }

    public function testTheModesGiveTheStepAndAdditionalFormsNever(): void
    {
        $host = new EventFormHost();

        self::assertSame(AccountStep::Required, $host->accountStep($this->readForm($this->openForm('mandatory'))));
        self::assertSame(AccountStep::Offered, $host->accountStep($this->readForm($this->openForm('optional'))));
        self::assertNull($host->accountStep($this->readForm($this->openForm('mandatory', 'additional'))));
    }

    /**
     * The account mode changes under a submit that conflicts on the event's version: the retry reads the event
     * again and decides the owner by the mode it saves under, never by the first attempt's (C3-1).
     */
    public function testTheOwnerFollowsTheModeTheSaveUsedAcrossAVersionConflictRetry(): void
    {
        foreach ([['off', 'mandatory', true], ['mandatory', 'off', false]] as [$from, $to, $owned]) {
            $form = $this->openForm($from);
            $user = $this->verifiedUser('teszt.' . $from . '@example.org');
            $event = (int) get_post_meta($form, 'kalaka_event_id', true);
            $changed = false;
            $change = function (string $sql) use (&$changed, $event, $to): string {
                global $wpdb;
                // Just before the run's savepoint, so the rollback of the conflicting attempt keeps the change.
                if (!$changed && str_starts_with($sql, 'SAVEPOINT kalaka_run')) {
                    $changed = true;
                    $wpdb->query($wpdb->prepare(
                        'UPDATE %i SET account_mode = %s, version = version + 1 WHERE id = %d',
                        $wpdb->prefix . 'kalaka_events',
                        $to,
                        $event
                    ));
                    ObjectCache::clear();
                }

                return $sql;
            };
            add_filter('query', $change);
            try {
                $answers = ['name' => 'Teszt Anna', 'email' => 'teszt.anna@example.org'];
                $result = $this->submit($form, new AccountActor($user), $answers);
            } finally {
                remove_filter('query', $change);
            }

            self::assertTrue($changed, $from);
            self::assertSame($owned ? $user : null, $this->ownerOf($result), $from . ' to ' . $to);
        }
    }

    public function testMandatoryModeRefusesAGuestSubmitWithNothingWritten(): void
    {
        $form = $this->openForm('mandatory');

        try {
            $this->submit($form);
            self::fail('The submit should have been refused.');
        } catch (FormRefused $refused) {
            self::assertSame([AccessRefusal::AccountRequired], $refused->refusals);
        }

        global $wpdb;
        self::assertSame('0', (string) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_registrations"));
    }

    public function testOptionalModeLetsAGuestSubmitWithNoAccount(): void
    {
        $result = $this->submit($this->openForm('optional'));

        self::assertNull($this->ownerOf($result));
        self::assertSame([], $this->accountMails());
    }

    public function testAVerifiedAccountOwnsTheRegistrationWithNoMail(): void
    {
        $user = $this->verifiedUser();

        $result = $this->submit($this->openForm('mandatory'), new AccountActor($user));

        self::assertSame($user, $this->ownerOf($result));
        self::assertSame([], $this->accountMails());
    }

    public function testAnUnverifiedAccountOwnsItWithNoMailOfItsOwnAndNoKeyYet(): void
    {
        $user = $this->unverifiedUser();

        $result = $this->submit($this->openForm('mandatory'), new AccountActor($user));

        self::assertSame($user, $this->ownerOf($result));
        // The set-password link travels in the status mail itself (S15-10): no mail of its own, and no key
        // before that mail is rendered.
        self::assertSame([], $this->accountMails());
        self::assertSame([], $this->mails);
        self::assertSame('', get_userdata($user)->user_activation_key);
    }

    /** Owner ruling 2026-11-18: no redirect and no refusal; the whole form shows with the account block. */
    public function testAGuestSeesTheWholeMandatoryFormWithTheAccountBlock(): void
    {
        $form = $this->openForm('mandatory');
        $here = home_url('/');

        $view = (new FormPage(new EventFormHost()))->open($form);

        self::assertNull($view->refusal);
        $account = $view->context['account'];
        self::assertIsArray($account);
        self::assertSame(SignUp::url($form, $here), $account['url']);
        self::assertStringContainsString(SignUp::MARKER . '=' . $form, $account['url']);
        self::assertStringContainsString(rawurlencode(SignUp::returnUrl($form, $here)), $account['url']);
        self::assertSame(admin_url('admin-ajax.php?action=rest-nonce'), $account['nonceUrl']);
        self::assertSame(AccountMarkup::ERROR, $account['error']);
        self::assertFalse($account['loggedIn']);
        self::assertSame('', $view->context['nonce']);

        $html = do_shortcode('[kalaka_form id="' . $form . '"]');
        self::assertStringContainsString('<form', $html);
        self::assertStringContainsString(
            '<div class="kalaka-account" data-kalaka-account="out"><p>' . AccountMarkup::REQUIRED . ' '
            . AccountMarkup::GIVES . '</p><p><button type="button" data-kalaka-account-login'
            . ' data-wp-on--click="actions.logIn">' . AccountMarkup::BUTTON . '</button></p>',
            $html
        );
        self::assertStringContainsString('id="kalaka-account-error"', $html);
        self::assertStringNotContainsString(AccountMarkup::AGE, $html);
        self::assertSame(1, substr_count($html, '<button type="button" data-kalaka-account-login'));
    }

    public function testInOptionalModeTheBlockHoldsWhatGoingWithoutCostsAndTheAgeNotice(): void
    {
        $html = do_shortcode('[kalaka_form id="' . $this->openForm('optional') . '"]');

        self::assertStringContainsString(
            '<p>' . AccountMarkup::GIVES . '</p><p>' . AccountMarkup::WITHOUT . '</p><p>'
            . AccountMarkup::AGE . '</p>',
            $html
        );
        self::assertStringNotContainsString(AccountMarkup::REQUIRED, $html);
    }

    /** #191 review B1/C1-1: the account comes after the form's other refusals, so a closed form says so. */
    public function testAClosedMandatoryFormSaysItIsClosed(): void
    {
        $form = $this->openForm('mandatory');
        $this->closeRegistration($form);

        self::assertSame(FormPageView::CLOSED, (new FormPage(new EventFormHost()))->open($form)->refusal);
    }

    public function testLoggedInTheFormShowsWithTheContactEmailFilledIn(): void
    {
        $form = $this->openForm('mandatory');
        wp_set_current_user($this->verifiedUser());

        $view = (new FormPage(new EventFormHost()))->open($form);

        self::assertNull($view->refusal);
        self::assertStringContainsString('value="teszt.csilla@example.org"', $view->content);
        self::assertTrue($view->context['account']['loggedIn']);
        self::assertSame('', $view->context['loginUrl'], 'a form with a block logs in again through it');
    }

    public function testTheLoggedInBlockNamesTheAccountWithTheLogoutBackToTheForm(): void
    {
        $form = $this->openForm('optional');
        wp_set_current_user($this->verifiedUser());

        $html = do_shortcode('[kalaka_form id="' . $form . '"]');

        self::assertStringContainsString(
            '<div class="kalaka-account" data-kalaka-account="in"><p>Bejelentkezve: teszt.csilla@example.org.'
            . ' Nem te vagy? <a href="' . esc_url(wp_logout_url(home_url('/'))) . '">Kijelentkezés</a></p></div>',
            $html
        );
        self::assertStringNotContainsString(AccountMarkup::BUTTON, $html);
    }

    public function testTheBlocksNameIsARealDisplayNameElseTheEmailEscaped(): void
    {
        $form = $this->openForm('mandatory');
        $html = static fn (): string => do_shortcode('[kalaka_form id="' . $form . '"]');

        // A Kaláka sign-up's display name is its login, even a suffixed one: the e-mail instead.
        $signUp = self::factory()->user->create([
            'user_login' => 'teszt.anna2',
            'user_email' => 'teszt.anna@example.org',
        ]);
        wp_set_current_user($signUp);
        self::assertSame('teszt.anna2', wp_get_current_user()->display_name);
        self::assertStringContainsString('Bejelentkezve: teszt.anna@example.org.', $html());

        $named = self::factory()->user->create([
            'user_email' => 'kovacs@example.org',
            'display_name' => 'Kovács Anna',
        ]);
        wp_set_current_user($named);
        self::assertStringContainsString('Bejelentkezve: Kovács Anna.', $html());

        // Stored as is (WordPress filters it on save), the name is escaped on the page.
        global $wpdb;
        $wpdb->update($wpdb->users, ['display_name' => 'Kovács <b>Anna</b> & "Co"'], ['ID' => $named]);
        clean_user_cache($named);
        wp_set_current_user(0);
        wp_set_current_user($named);
        $escaped = 'Bejelentkezve: Kovács &lt;b&gt;Anna&lt;/b&gt; &amp; &quot;Co&quot;.';
        self::assertStringContainsString($escaped, $html());
        self::assertStringNotContainsString('<b>Anna', $html());
    }

    /** Off mode, or an additional form: the block renders nothing, logged in or not, and the context has none. */
    public function testTheBlockRendersNothingInOffModeOrOnAnAdditionalForm(): void
    {
        $off = $this->openForm('off');
        $additional = $this->openForm(
            'mandatory',
            'additional',
            self::ACCOUNT_BLOCK . $this->field('text', 'megjegyzes')
        );
        foreach ([0, $this->verifiedUser()] as $user) {
            wp_set_current_user($user);
            $view = (new FormPage(new EventFormHost()))->open($off);
            self::assertNull($view->refusal);
            self::assertNull($view->context['account']);
            $html = do_shortcode('[kalaka_form id="' . $off . '"]');
            self::assertStringNotContainsString('data-kalaka-account', $html);
            self::assertStringNotContainsString('Bejelentkezve:', $html);

            // Without its registration's link an additional form is refused (S60); the block still shows nothing.
            $view = (new FormPage(new EventFormHost()))->open($additional);
            self::assertSame(FormPageView::NO_ACTIVE_REGISTRATION, $view->refusal);
            self::assertSame([], $view->context);
            $html = do_shortcode('[kalaka_form id="' . $additional . '"]');
            self::assertStringNotContainsString('data-kalaka-account', $html);
            self::assertStringNotContainsString('Bejelentkezve:', $html);
        }
    }

    /** Outside a form page (a post that has the block by itself), nothing. */
    public function testTheBlockOutsideAFormRendersNothing(): void
    {
        self::assertSame('', trim(do_blocks(self::ACCOUNT_BLOCK)));
    }
}
