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
use Kalaka\Mail\Api\AccountMail;
use Kalaka\Registrations\Api\SubmitToken;

/**
 * S15: the event's account mode on its forms and their submits. Off changes nothing; in mandatory mode
 * someone not logged in is sent to sign up and cannot submit; in optional mode they may skip; a logged-in
 * person owns the registration, and an unverified account gets the set-password mail with it, queued only.
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

    public function testAnUnverifiedAccountOwnsItAndTheSetPasswordMailIsQueuedOnly(): void
    {
        $user = $this->unverifiedUser();

        $result = $this->submit($this->openForm('mandatory'), new AccountActor($user));

        self::assertSame($user, $this->ownerOf($result));
        $mails = $this->accountMails();
        self::assertCount(1, $mails);
        self::assertSame(AccountMail::SetPassword->value, $mails[0]['mail']);
        self::assertSame('action', $mails[0]['kind']);
        self::assertSame('account', $mails[0]['cause_type']);
        self::assertSame((string) $user, $mails[0]['cause_key']);
        self::assertSame($result->registration->code, $mails[0]['code']);
        // No mail inside the request, and no reset key before the mail is composed.
        self::assertSame([], $this->mails);
        self::assertSame('', get_userdata($user)->user_activation_key);
    }

    public function testARepeatedSubmitQueuesNothingMore(): void
    {
        $form = $this->openForm('mandatory');
        $user = $this->unverifiedUser();
        $token = SubmitToken::generate();

        $this->submit($form, new AccountActor($user), self::ANSWERS, $token);
        $repeat = $this->submit($form, new AccountActor($user), self::ANSWERS, $token);

        self::assertTrue($repeat->repeated);
        self::assertCount(1, $this->accountMails());
    }

    public function testTheFormPageLinksToSignUpForSomeoneNotLoggedIn(): void
    {
        $form = $this->openForm('mandatory');

        $view = (new FormPage(new EventFormHost()))->open($form);

        self::assertSame(FormPageView::ACCOUNT_REQUIRED, $view->refusal);
        self::assertStringContainsString('action=register', $view->signUpUrl);
        self::assertStringContainsString(SignUp::MARKER . '=' . $form, $view->signUpUrl);
        self::assertStringContainsString('redirect_to=', $view->signUpUrl);

        $html = do_shortcode('[kalaka_form id="' . $form . '"]');
        self::assertStringContainsString('A jelentkezéshez fiók szükséges.', $html);
        self::assertStringContainsString('action=register', $html);
        self::assertStringNotContainsString('<form', $html);
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
}
