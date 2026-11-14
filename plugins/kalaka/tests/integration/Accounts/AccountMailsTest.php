<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Accounts;

use Kalaka\Events\PublishEvent;
use Kalaka\Forms\Api\AccountChoice;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Mail\AccountMailComposer;
use Kalaka\Mail\Api\MailQueue;
use Kalaka\Mail\Body\ActionMailComposer;
use Kalaka\Mail\Sender;
use Kalaka\Portal\AttachHandler;
use Kalaka\Registrations\Api\AttachLinks;
use Kalaka\Registrations\Api\RegistrationAttach;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationSubmit;
use Kalaka\Registrations\Api\SubmitRequest;
use Kalaka\Registrations\Api\SubmitResult;
use Kalaka\Registrations\Api\SubmitToken;
use Kalaka\Registrations\RegistrationRepository;
use Kalaka\Registrations\SecretLink;
use Kalaka\Tests\Integration\Forms\FormsTestCase;
use Kalaka\Tests\Integration\Portal\RecordingResponse;

/**
 * S15: the two account mails as the sender sends them (the set-password link with a fresh WordPress reset
 * key, the attach link), and the attach link followed: by its account only, once, revoked by a new secret.
 */
final class AccountMailsTest extends FormsTestCase
{
    /** @var list<array<string, mixed>> what `wp_mail` was called with */
    private array $mails = [];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->mails = [];
        remove_all_actions(MailQueue::SEND_HOOK);
        remove_all_filters('pre_wp_mail');
        add_filter('pre_wp_mail', function (mixed $result, array $atts): bool {
            unset($result);
            $this->mails[] = $atts;

            return true;
        }, 10, 2);
        wp_set_current_user(0);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        remove_all_filters('pre_wp_mail');
        wp_unschedule_hook(MailQueue::SEND_HOOK);
        wp_set_current_user(0);

        parent::tear_down();
    }

    private function openForm(): int
    {
        $event = $this->create('Nyári tábor');
        $this->update($event, ['capacity' => 10, 'name' => 'Nyári tábor']);
        $this->transition(new PublishEvent(), $event);
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_events', ['account_mode' => 'mandatory'], ['id' => $event]);
        ObjectCache::clear();

        return $this->insertForm($this->slotFields(), ['kalaka_event_id' => $event]);
    }

    private function signUp(int $form, string $name, string $email): SubmitResult
    {
        $version = $this->forms->form($form)?->version ?? 1;

        return (new RegistrationSubmit($this->runner))->submit(
            new SubmitRequest(
                $form,
                $version,
                ['name' => $name, 'email' => $email],
                SubmitToken::generate(),
                [],
                false,
                AccountChoice::SignUp
            ),
            new GuestActor()
        );
    }

    private function send(): void
    {
        // The one bound composer: it hands the account mails to `AccountMailComposer`.
        (new Sender(fn () => $this->runner, new ActionMailComposer()))->send();
    }

    /** @return array<array-key, mixed> the URL's query parameters */
    private static function query(string $url): array
    {
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    /** The first link of the mail's body whose address contains the text. */
    private static function linkIn(string $body, string $contains): string
    {
        preg_match_all('/href="([^"]+)"/', $body, $all);
        foreach ($all[1] as $href) {
            $href = html_entity_decode($href);
            if (str_contains($href, $contains)) {
                return $href;
            }
        }
        self::fail('No link with ' . $contains . ' in the mail.');
    }

    public function testTheSetPasswordMailCarriesAFreshResetKeyForWordPresssOwnScreenAndTheSecretLink(): void
    {
        $result = $this->signUp($this->openForm(), 'Teszt Anna', 'teszt.anna@example.org');
        self::assertSame([], $this->mails);

        $this->send();

        self::assertCount(1, $this->mails);
        $mail = $this->mails[0];
        self::assertSame('teszt.anna@example.org', $mail['to']);
        self::assertSame(AccountMailComposer::SET_PASSWORD_SUBJECT . ' – Nyári tábor', $mail['subject']);
        $link = self::linkIn((string) $mail['message'], 'action=rp');
        self::assertStringContainsString('wp-login.php', $link);
        $query = self::query($link);
        $user = get_user_by('email', 'teszt.anna@example.org');
        self::assertInstanceOf(\WP_User::class, $user);
        self::assertSame($user->user_login, $query['login']);
        // WordPress's own check of the key accepts it: the "set new password" screen opens.
        self::assertInstanceOf(\WP_User::class, check_password_reset_key((string) $query['key'], $user->user_login));
        $key = $result->registration;
        $secretLink = 'kalaka_link=' . $key->eventId . '.' . $key->code . '.';
        self::assertStringContainsString($secretLink, (string) $mail['message']);

        global $wpdb;
        $status = $wpdb->get_var("SELECT status FROM {$wpdb->prefix}kalaka_deliveries");
        self::assertSame('sent', $status);
    }

    public function testTheAttachMailGoesToTheAccountAndItsLinkAttachesTheRegistrationToItOnly(): void
    {
        $holder = self::factory()->user->create(['user_email' => 'teszt.csilla@example.org', 'role' => 'subscriber']);
        $other = self::factory()->user->create(['user_email' => 'teszt.emil@example.org', 'role' => 'subscriber']);
        $result = $this->signUp($this->openForm(), 'Teszt Csilla', 'teszt.csilla@example.org');

        $this->send();

        self::assertCount(1, $this->mails);
        self::assertSame('teszt.csilla@example.org', $this->mails[0]['to']);
        self::assertSame(AccountMailComposer::ATTACH_SUBJECT . ' – Nyári tábor', $this->mails[0]['subject']);
        $link = self::linkIn((string) $this->mails[0]['message'], AttachLinks::PARAMETER . '=');
        $query = self::query($link);
        $value = (string) $query[AttachLinks::PARAMETER];

        $attach = new RegistrationAttach($this->runner);
        try {
            $attach->attach($value, $other);
            self::fail('Another account must not attach it.');
        } catch (PermissionDenied) {
        }
        self::assertNull((new Registrations())->find($result->registration)?->ownerUserId);

        self::assertTrue($attach->attach($value, $holder));
        self::assertSame($holder, (new Registrations())->find($result->registration)?->ownerUserId);
        // Again: it stays the holder's.
        self::assertTrue($attach->attach($value, $holder));
    }

    public function testAnOwnedRegistrationKeepsItsOwner(): void
    {
        $holder = self::factory()->user->create(['user_email' => 'teszt.csilla@example.org']);
        $result = $this->signUp($this->openForm(), 'Teszt Csilla', 'teszt.csilla@example.org');
        $repository = new RegistrationRepository();
        $registration = $repository->find($result->registration);
        self::assertNotNull($registration);
        global $wpdb;
        $first = self::factory()->user->create();
        $wpdb->update(
            $wpdb->prefix . 'kalaka_registrations',
            ['owner_user_id' => $first],
            ['event_id' => $result->registration->eventId, 'code' => $result->registration->code]
        );
        ObjectCache::clear();

        $link = (new AttachLinks())->url($result->registration, $holder, home_url('/'));
        self::assertNotNull($link);
        $query = self::query($link);

        $value = (string) $query[AttachLinks::PARAMETER];
        self::assertFalse((new RegistrationAttach($this->runner))->attach($value, $holder));
        self::assertSame($first, (new Registrations())->find($result->registration)?->ownerUserId);
    }

    public function testANewSecretRevokesTheAttachLink(): void
    {
        $holder = self::factory()->user->create(['user_email' => 'teszt.csilla@example.org']);
        $result = $this->signUp($this->openForm(), 'Teszt Csilla', 'teszt.csilla@example.org');
        $link = (new AttachLinks())->url($result->registration, $holder, home_url('/'));
        self::assertNotNull($link);
        $query = self::query($link);
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'kalaka_registrations',
            ['secret' => SecretLink::generate()],
            ['event_id' => $result->registration->eventId, 'code' => $result->registration->code]
        );
        ObjectCache::clear();

        $this->expectException(PermissionDenied::class);
        (new RegistrationAttach($this->runner))->attach((string) $query[AttachLinks::PARAMETER], $holder);
    }

    public function testTheHandlerSendsSomeoneNotLoggedInToLogInAndBackAndAttachesOnceLoggedIn(): void
    {
        $holder = self::factory()->user->create(['user_email' => 'teszt.csilla@example.org']);
        $result = $this->signUp($this->openForm(), 'Teszt Csilla', 'teszt.csilla@example.org');
        $link = (string) (new AttachLinks())->url($result->registration, $holder, '/');
        $query = self::query($link);
        $url = '/?' . AttachLinks::PARAMETER . '=' . $query[AttachLinks::PARAMETER];

        $response = new RecordingResponse();
        (new AttachHandler($response, new RegistrationAttach($this->runner)))->handle($query, 'GET', $url, 0);
        self::assertNotNull($response->redirect);
        self::assertStringContainsString('wp-login.php', $response->redirect);
        self::assertStringContainsString('redirect_to=' . rawurlencode($url), $response->redirect);
        self::assertSame(1, $response->noCache);
        self::assertNull((new Registrations())->find($result->registration)?->ownerUserId);

        $response = new RecordingResponse();
        (new AttachHandler($response, new RegistrationAttach($this->runner)))->handle($query, 'GET', $url, $holder);
        self::assertSame('/', $response->redirect);
        self::assertSame($holder, (new Registrations())->find($result->registration)?->ownerUserId);
    }

    public function testTheHandlerAttachesNothingForAnotherAccountAndStillDropsTheLink(): void
    {
        $holder = self::factory()->user->create(['user_email' => 'teszt.csilla@example.org']);
        $other = self::factory()->user->create();
        $result = $this->signUp($this->openForm(), 'Teszt Csilla', 'teszt.csilla@example.org');
        $link = (string) (new AttachLinks())->url($result->registration, $holder, '/');
        $query = self::query($link);

        $response = new RecordingResponse();
        (new AttachHandler($response, new RegistrationAttach($this->runner)))
            ->handle($query, 'GET', '/?' . AttachLinks::PARAMETER . '=x', $other);

        self::assertSame('/', $response->redirect);
        self::assertNull((new Registrations())->find($result->registration)?->ownerUserId);
        self::assertInstanceOf(RegistrationKey::class, $result->registration);
    }
}
