<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Accounts;

use Kalaka\Events\PublishEvent;
use Kalaka\Forms\Api\AccountChoice;
use Kalaka\Forms\Api\AccountStep;
use Kalaka\Forms\Api\FormRefused;
use Kalaka\Forms\Engine\FormPage;
use Kalaka\Forms\EventFormHost;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Mail\AccountMailComposer;
use Kalaka\Registrations\Account\AccountOutcome;
use Kalaka\Registrations\Account\AccountSignUp;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationSubmit;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\SubmitRequest;
use Kalaka\Registrations\Api\SubmitResult;
use Kalaka\Registrations\Api\SubmitToken;
use Kalaka\Tests\Integration\Forms\FormsTestCase;

/**
 * S15: the event's account mode on its registration forms. The account step for someone not logged in,
 * the owner at submit, the sign-up creating the WordPress user before the transaction, an email that
 * already has an account (no reveal, the attach mail), skip, and that no mail is sent in the request.
 */
final class AccountsOnFormTest extends FormsTestCase
{
    private const ANSWERS = ['name' => 'Teszt Anna', 'email' => 'teszt.anna@example.org'];

    /** @var int how many times `wp_mail` was called */
    private int $mailCalls = 0;

    /** @var int how many passwords WordPress generated */
    private int $passwords = 0;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->mailCalls = 0;
        $this->passwords = 0;
        add_filter('pre_wp_mail', [$this, 'countMail'], 10, 1);
        add_filter('random_password', [$this, 'countPassword'], 10, 1);
        wp_set_current_user(0);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        remove_filter('pre_wp_mail', [$this, 'countMail'], 10);
        remove_filter('random_password', [$this, 'countPassword'], 10);
        wp_set_current_user(0);

        parent::tear_down();
    }

    /** @internal */
    public function countMail(mixed $result): bool
    {
        unset($result);
        $this->mailCalls++;

        return true;
    }

    /** @internal */
    public function countPassword(string $password): string
    {
        $this->passwords++;

        return $password;
    }

    /** A published event in the account mode, and a registration-slot form of it (or an additional form). */
    private function openForm(string $mode, string $placement = 'registration'): int
    {
        $event = $this->create();
        $this->update($event, ['capacity' => 10]);
        $this->transition(new PublishEvent(), $event);
        $this->setMode($event, $mode);
        $content = $placement === 'registration' ? $this->slotFields() : $this->field('text', 'megjegyzes');

        return $this->insertForm($content, ['kalaka_event_id' => $event, 'kalaka_placement' => $placement]);
    }

    private function setMode(int $event, string $mode): void
    {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_events', ['account_mode' => $mode], ['id' => $event]);
        ObjectCache::clear();
    }

    /** @param array<string, mixed> $answers */
    private function submit(
        int $form,
        ?AccountChoice $account,
        ?Actor $actor = null,
        array $answers = self::ANSWERS,
        ?string $token = null,
    ): SubmitResult {
        $version = $this->forms->form($form)?->version ?? 1;

        return (new RegistrationSubmit($this->runner))->submit(
            new SubmitRequest($form, $version, $answers, $token ?? SubmitToken::generate(), [], false, $account),
            $actor ?? new GuestActor()
        );
    }

    private function eventOf(int $form): int
    {
        return $this->readForm($form)->eventId;
    }

    private function ownerOf(SubmitResult $result): ?int
    {
        return (new Registrations())->find($result->registration)?->ownerUserId;
    }

    /** @return list<array<string, ?string>> */
    private function deliveries(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}kalaka_deliveries ORDER BY id", ARRAY_A);
        self::assertIsArray($rows);

        return $rows;
    }

    private function registrationCount(int $event): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_registrations WHERE event_id = %d",
            $event
        ));
    }

    private function userCount(): int
    {
        return (int) count_users()['total_users'];
    }

    private function holder(string $email = 'teszt.csilla@example.org'): int
    {
        return self::factory()->user->create(['user_email' => $email, 'role' => 'subscriber']);
    }

    public function testModeOffIsTheBehaviourBeforeAccountsLoggedInOrNot(): void
    {
        $form = $this->openForm('off');
        $user = $this->holder();

        $guest = $this->submit($form, null);
        $loggedIn = $this->submit(
            $form,
            null,
            new AccountActor($user),
            ['name' => 'Teszt Csilla', 'email' => 'teszt.csilla2@example.org']
        );

        self::assertNull($this->ownerOf($guest));
        self::assertNull($this->ownerOf($loggedIn));
        self::assertSame([], $this->deliveries());
        self::assertNull((new EventFormHost())->accountStep($this->readForm($form)));
    }

    public function testModeMandatoryAndOptionalGiveTheStepAndAdditionalFormsNever(): void
    {
        $mandatory = $this->readForm($this->openForm('mandatory'));
        $optional = $this->readForm($this->openForm('optional'));
        $additional = $this->readForm($this->openForm('mandatory', 'additional'));

        $host = new EventFormHost();
        self::assertSame(AccountStep::Required, $host->accountStep($mandatory));
        self::assertSame(AccountStep::Offered, $host->accountStep($optional));
        self::assertNull($host->accountStep($additional));
    }

    public function testTheFormPageBeginsWithTheStepForSomeoneNotLoggedIn(): void
    {
        $form = $this->openForm('optional');

        $view = (new FormPage(new EventFormHost()))->open($form);

        self::assertNotNull($view->account);
        self::assertTrue($view->account['skip']);
        self::assertSame('email', $view->account['email']['key']);
        self::assertSame('name', $view->account['name']['key']);
        self::assertStringContainsString('wp-login.php', $view->account['loginUrl']);
        self::assertStringContainsString('redirect_to=', $view->account['loginUrl']);
        self::assertSame(['skip' => true, 'email' => 'email', 'name' => 'name'], $view->context['account']);

        $html = render_block(['blockName' => 'kalaka/form', 'attrs' => ['formId' => $form], 'innerBlocks' => []]);
        self::assertStringContainsString('data-kalaka-account-step', $html);
        self::assertStringContainsString('Regisztrálok', $html);
        self::assertStringContainsString('Bejelentkezem', $html);
        self::assertStringContainsString('Fiók nélkül folytatom', $html);
        self::assertStringContainsString('<div data-kalaka-form-body hidden>', $html);
    }

    public function testMandatoryModeOffersNoSkip(): void
    {
        $form = $this->openForm('mandatory');

        $html = render_block(['blockName' => 'kalaka/form', 'attrs' => ['formId' => $form], 'innerBlocks' => []]);

        self::assertStringContainsString('data-kalaka-account-step', $html);
        self::assertStringNotContainsString('data-kalaka-account-skip', $html);
    }

    public function testTheShortcodeRendersTheSameStepForDivi(): void
    {
        $form = $this->openForm('mandatory');

        $html = do_shortcode('[kalaka_form id="' . $form . '"]');

        self::assertStringContainsString('data-kalaka-account-step', $html);
        self::assertStringContainsString('<div data-kalaka-form-body hidden>', $html);
    }

    public function testLoggedInNoStepAndTheContactEmailStartsWithTheAccountsAddress(): void
    {
        $form = $this->openForm('mandatory');
        wp_set_current_user($this->holder());

        $view = (new FormPage(new EventFormHost()))->open($form);

        self::assertNull($view->account);
        self::assertNull($view->context['account']);
        self::assertStringContainsString('value="teszt.csilla@example.org"', $view->content);
    }

    public function testAModeOffFormHasNoStepAndNothingFilledIn(): void
    {
        $form = $this->openForm('off');
        wp_set_current_user($this->holder());

        $view = (new FormPage(new EventFormHost()))->open($form);

        self::assertNull($view->account);
        self::assertStringNotContainsString('teszt.csilla@example.org', $view->content);
    }

    public function testALoggedInSubmitAttachesTheRegistrationWithNoMail(): void
    {
        $form = $this->openForm('mandatory');
        $user = $this->holder();

        $result = $this->submit($form, null, new AccountActor($user));

        self::assertSame($user, $this->ownerOf($result));
        self::assertSame([], $this->deliveries());
    }

    public function testNotLoggedInTheStepsChoiceIsRequiredAndSkipOnlyInOptionalMode(): void
    {
        $mandatory = $this->openForm('mandatory');
        $users = $this->userCount();

        foreach ([null, AccountChoice::Skip] as $choice) {
            try {
                $this->submit($mandatory, $choice);
                self::fail('The submit should have been refused.');
            } catch (InvalidInput) {
            }
        }

        self::assertSame(0, $this->registrationCount($this->eventOf($mandatory)));
        self::assertSame($users, $this->userCount());

        try {
            $this->submit($this->openForm('optional'), null);
            self::fail('The submit should have been refused.');
        } catch (InvalidInput) {
        }
    }

    public function testSkipInOptionalModeLandsWithNoAccountAndNoMail(): void
    {
        $form = $this->openForm('optional');
        $users = $this->userCount();

        $result = $this->submit($form, AccountChoice::Skip);

        self::assertNull($this->ownerOf($result));
        self::assertSame([], $this->deliveries());
        self::assertSame($users, $this->userCount());
    }

    public function testSignUpCreatesTheUserAtSubmitOwnsTheRegistrationAndQueuesTheSetPasswordMail(): void
    {
        $form = $this->openForm('mandatory');

        $result = $this->submit($form, AccountChoice::SignUp);

        $user = get_user_by('email', 'teszt.anna@example.org');
        self::assertInstanceOf(\WP_User::class, $user);
        self::assertSame(['subscriber'], array_values($user->roles));
        self::assertSame('Teszt Anna', $user->display_name);
        self::assertSame('teszt.anna', $user->user_login);
        self::assertSame($user->ID, $this->ownerOf($result));
        // The reset key is made when the mail is sent, not at submit.
        self::assertSame('', $user->user_activation_key);

        $deliveries = $this->deliveries();
        self::assertCount(1, $deliveries);
        self::assertSame(AccountOutcome::SET_PASSWORD, $deliveries[0]['mail']);
        self::assertSame('action', $deliveries[0]['kind']);
        self::assertSame('pending', $deliveries[0]['status']);
        self::assertSame('account', $deliveries[0]['cause_type']);
        self::assertSame((string) $user->ID, $deliveries[0]['cause_key']);
        self::assertSame($result->registration->code, $deliveries[0]['code']);
        // No mail inside the request: the queue only.
        self::assertSame(0, $this->mailCalls);
    }

    public function testAnExistingEmailCreatesNoUserRevealsNothingAndQueuesTheAttachMail(): void
    {
        $holder = $this->holder('teszt.csilla@example.org');
        $form = $this->openForm('mandatory');

        $this->passwords = 0;
        $new = $this->submit($form, AccountChoice::SignUp);
        $newPasswords = $this->passwords;
        $users = $this->userCount();

        $this->passwords = 0;
        $existing = $this->submit(
            $form,
            AccountChoice::SignUp,
            null,
            ['name' => 'Teszt Csilla', 'email' => 'teszt.csilla@example.org']
        );

        self::assertSame($users, $this->userCount());
        self::assertNull($this->ownerOf($existing));
        // The same answer, and the same work: one password hashed each way.
        $shape = static fn (array $answer): array => array_keys($answer);
        self::assertSame($shape($new->toArray()), $shape($existing->toArray()));
        self::assertSame($new->toArray()['after_submit'], $existing->toArray()['after_submit']);
        self::assertSame($new->toArray()['status'], $existing->toArray()['status']);
        self::assertSame(1, $newPasswords);
        self::assertSame(1, $this->passwords);

        $attach = $this->deliveries()[1];
        self::assertSame(AccountOutcome::ATTACH, $attach['mail']);
        self::assertSame('account', $attach['cause_type']);
        self::assertSame((string) $holder, $attach['cause_key']);
        self::assertSame($existing->registration->code, $attach['code']);
        self::assertSame(0, $this->mailCalls);
    }

    public function testTheEmailIsMatchedAsWordPressMatchesIt(): void
    {
        $holder = $this->holder('teszt.csilla@example.org');
        $form = $this->openForm('mandatory');

        $result = $this->submit(
            $form,
            AccountChoice::SignUp,
            null,
            ['name' => 'Teszt Csilla', 'email' => 'Teszt.Csilla@example.org']
        );

        self::assertNull($this->ownerOf($result));
        self::assertSame((string) $holder, $this->deliveries()[0]['cause_key']);
    }

    public function testARepeatedSubmitCreatesNoSecondUserAndQueuesNothingMore(): void
    {
        $form = $this->openForm('mandatory');
        $token = SubmitToken::generate();

        $first = $this->submit($form, AccountChoice::SignUp, null, self::ANSWERS, $token);
        $users = $this->userCount();
        $repeat = $this->submit($form, AccountChoice::SignUp, null, self::ANSWERS, $token);

        self::assertTrue($repeat->repeated);
        self::assertSame($users, $this->userCount());
        self::assertCount(1, $this->deliveries());
        self::assertNotNull($this->ownerOf($first));
    }

    public function testARefusedSubmitCreatesNoUser(): void
    {
        $form = $this->openForm('mandatory');
        $event = $this->eventOf($form);
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'kalaka_events',
            ['registration_closes_at' => '2026-11-30 07:30:00.000000'],
            ['id' => $event]
        );
        ObjectCache::clear();
        $users = $this->userCount();

        try {
            $this->submit($form, AccountChoice::SignUp);
            self::fail('The submit should have been refused.');
        } catch (FormRefused) {
        }

        self::assertSame($users, $this->userCount());
        self::assertFalse(get_user_by('email', 'teszt.anna@example.org'));
    }

    public function testTheLoginNameIsTheLocalPartMadeFree(): void
    {
        self::factory()->user->create(['user_login' => 'teszt.anna', 'user_email' => 'other@example.org']);

        self::assertSame('teszt.anna2', AccountSignUp::login('teszt.anna@example.org'));
        self::assertSame('resztvevo', AccountSignUp::login('!!!@example.org'));
    }

    public function testTheComposerAndTheQueueAgreeOnTheMailKeys(): void
    {
        self::assertSame(AccountOutcome::SET_PASSWORD, AccountMailComposer::SET_PASSWORD);
        self::assertSame(AccountOutcome::ATTACH, AccountMailComposer::ATTACH);
    }

    public function testTheRegistrationsKeyIsTheResultsKey(): void
    {
        $form = $this->openForm('optional');

        $result = $this->submit($form, AccountChoice::Skip);

        self::assertInstanceOf(RegistrationKey::class, $result->registration);
        self::assertSame($this->eventOf($form), $result->registration->eventId);
    }
}
