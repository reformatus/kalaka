<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Accounts;

use Kalaka\Forms\Api\AccessRefusal;
use Kalaka\Forms\Api\AccountStep;
use Kalaka\Forms\Api\FormRefused;
use Kalaka\Forms\Api\SignUp;
use Kalaka\Forms\Engine\FormPage;
use Kalaka\Forms\Engine\FormPageView;
use Kalaka\Forms\EventFormHost;
use Kalaka\Foundation\Api\Actor\AccountActor;

/**
 * S15: the event's account mode on its forms and their submits. Off changes nothing; in mandatory mode
 * someone not logged in is sent to sign up and cannot submit; in optional mode they may skip; a logged-in
 * person owns the registration.
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

    public function testTheFormPageLinksToSignUpForSomeoneNotLoggedIn(): void
    {
        $form = $this->openForm('mandatory');

        $view = (new FormPage(new EventFormHost()))->open($form);

        self::assertSame(FormPageView::ACCOUNT_REQUIRED, $view->refusal);
        // OP5: the login page first.
        self::assertStringContainsString('wp-login.php', $view->signUpUrl);
        self::assertStringNotContainsString('action=register', $view->signUpUrl);
        self::assertStringContainsString(SignUp::MARKER . '=' . $form, $view->signUpUrl);
        self::assertStringContainsString('redirect_to=', $view->signUpUrl);

        $html = do_shortcode('[kalaka_form id="' . $form . '"]');
        self::assertStringContainsString('A jelentkezéshez fiók szükséges.', $html);
        self::assertStringContainsString(esc_url($view->signUpUrl), $html);
        self::assertStringNotContainsString('<form', $html);
    }

    /** #191 review B1/C1-1: the account comes after the form's other refusals, so a closed form says so first. */
    public function testAClosedMandatoryFormSaysItIsClosedBeforeAskingForAnAccount(): void
    {
        $form = $this->openForm('mandatory');
        $this->closeRegistration($form);

        $view = (new FormPage(new EventFormHost()))->open($form);

        self::assertNotSame(FormPageView::ACCOUNT_REQUIRED, $view->refusal);
        self::assertNotNull($view->refusal);
        self::assertSame('', $view->signUpUrl);
    }

    public function testInOptionalModeTheSkipMarkerShowsTheFormAndOnlyThere(): void
    {
        $form = $this->openForm('optional');
        $mandatory = $this->openForm('mandatory');

        self::assertSame(FormPageView::ACCOUNT_REQUIRED, (new FormPage(new EventFormHost()))->open($form)->refusal);

        $_GET = [SignUp::SKIP => (string) $form];
        self::assertNull((new FormPage(new EventFormHost()))->open($form)->refusal);

        $_GET = [SignUp::SKIP => (string) $mandatory];
        $page = new FormPage(new EventFormHost());
        self::assertSame(FormPageView::ACCOUNT_REQUIRED, $page->open($mandatory)->refusal);
    }

    public function testLoggedInTheFormShowsWithTheContactEmailFilledIn(): void
    {
        $form = $this->openForm('mandatory');
        wp_set_current_user($this->verifiedUser());

        $view = (new FormPage(new EventFormHost()))->open($form);

        self::assertNull($view->refusal);
        self::assertStringContainsString('value="teszt.csilla@example.org"', $view->content);
    }

    public function testAnAdditionalFormIsNeverRefusedForAnAccount(): void
    {
        $form = $this->openForm('mandatory', 'additional');

        self::assertNotSame(FormPageView::ACCOUNT_REQUIRED, (new FormPage(new EventFormHost()))->open($form)->refusal);
    }

    public function testTheFooterNamesTheAccountOnlyWhenLoggedIn(): void
    {
        $form = $this->openForm('optional');

        $_GET = [SignUp::SKIP => (string) $form];
        $guest = (new FormPage(new EventFormHost()))->open($form);
        self::assertNull($guest->refusal);
        self::assertSame('', $guest->accountName);
        self::assertStringNotContainsString('Bejelentkezve:', do_shortcode('[kalaka_form id="' . $form . '"]'));
        $_GET = [];

        wp_set_current_user($this->verifiedUser());
        $view = (new FormPage(new EventFormHost()))->open($form);
        self::assertSame(wp_logout_url(home_url('/')), $view->logoutUrl);
        $html = do_shortcode('[kalaka_form id="' . $form . '"]');
        self::assertStringContainsString('Bejelentkezve: teszt.csilla@example.org. Nem te vagy?', $html);
        self::assertStringContainsString(
            '<a href="' . esc_url(wp_logout_url(home_url('/'))) . '">Kijelentkezés</a>',
            $html
        );
    }

    public function testTheFootersNameIsARealDisplayNameElseTheEmailEscaped(): void
    {
        $form = $this->openForm('mandatory');
        $name = static fn (): string => (new FormPage(new EventFormHost()))->open($form)->accountName;

        // A Kaláka sign-up's display name is its login, even a suffixed one: the e-mail instead.
        $signUp = self::factory()->user->create([
            'user_login' => 'teszt.anna2',
            'user_email' => 'teszt.anna@example.org',
        ]);
        wp_set_current_user($signUp);
        self::assertSame('teszt.anna2', wp_get_current_user()->display_name);
        self::assertSame('teszt.anna@example.org', $name());

        $named = self::factory()->user->create([
            'user_email' => 'kovacs@example.org',
            'display_name' => 'Kovács Anna',
        ]);
        wp_set_current_user($named);
        self::assertSame('Kovács Anna', $name());

        // Stored as is (WordPress filters it on save), the name is escaped on the page.
        global $wpdb;
        $wpdb->update($wpdb->users, ['display_name' => 'Kovács <b>Anna</b> & "Co"'], ['ID' => $named]);
        clean_user_cache($named);
        wp_set_current_user(0);
        wp_set_current_user($named);
        $html = do_shortcode('[kalaka_form id="' . $form . '"]');
        self::assertStringContainsString(
            'Bejelentkezve: Kovács &lt;b&gt;Anna&lt;/b&gt; &amp; &quot;Co&quot;.',
            $html
        );
        self::assertStringNotContainsString('<b>Anna', $html);
    }

    public function testNoFooterInOffModeOrOnAnAdditionalForm(): void
    {
        wp_set_current_user($this->verifiedUser());

        foreach ([$this->openForm('off'), $this->openForm('mandatory', 'additional')] as $form) {
            $view = (new FormPage(new EventFormHost()))->open($form);
            self::assertSame('', $view->accountName);
            self::assertStringNotContainsString('Bejelentkezve:', do_shortcode('[kalaka_form id="' . $form . '"]'));
        }
    }
}
