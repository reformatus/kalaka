<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Accounts;

use Kalaka\Events\PublishEvent;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Mail\Api\MailQueue;
use Kalaka\Registrations\Account\SignUpPage;
use Kalaka\Registrations\Api\RegistrationSubmit;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\SubmitRequest;
use Kalaka\Registrations\Api\SubmitResult;
use Kalaka\Registrations\Api\SubmitToken;
use Kalaka\Tests\Integration\Forms\FormsTestCase;

/**
 * The base of the S15 tests: forms of an event in an account mode, a submit as a guest or an account,
 * `wp_mail` answered by a recorder, and the sign-up flag cleared around each test.
 */
abstract class AccountsTestCase extends FormsTestCase
{
    protected const ANSWERS = ['name' => 'Teszt Anna', 'email' => 'teszt.anna@example.org'];

    protected const ACCOUNT_BLOCK = '<!-- wp:kalaka/account /-->';

    /** @var list<array<string, mixed>> what `wp_mail` was called with */
    protected array $mails = [];

    /** @var array<int, string> form ID → the permalink of the published page placing it */
    private array $pages = [];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress's name
    public function set_up(): void
    {
        parent::set_up();

        $this->mails = [];
        $this->pages = [];
        remove_all_actions(MailQueue::SEND_HOOK);
        add_filter('pre_wp_mail', [$this, 'recordMail'], 10, 2);
        add_filter('send_auth_cookies', '__return_false');
        SignUpPage::reset();
        wp_set_current_user(0);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress's name
    public function tear_down(): void
    {
        remove_filter('pre_wp_mail', [$this, 'recordMail'], 10);
        remove_filter('send_auth_cookies', '__return_false');
        wp_unschedule_hook(MailQueue::SEND_HOOK);
        SignUpPage::reset();
        wp_set_current_user(0);
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        unset($_SERVER['REQUEST_METHOD']);

        parent::tear_down();
    }

    /**
     * @internal
     * @param array<string, mixed> $atts
     */
    public function recordMail(mixed $result, array $atts): bool
    {
        unset($result);
        $this->mails[] = $atts;

        return true;
    }

    /** A published event in the account mode, and a form of it in the placement (with `$content`, that). */
    protected function openForm(string $mode, string $placement = 'registration', ?string $content = null): int
    {
        $event = $this->create('Nyári tábor');
        $this->update($event, ['capacity' => 10, 'name' => 'Nyári tábor']);
        $this->transition(new PublishEvent(), $event);
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_events', ['account_mode' => $mode], ['id' => $event]);
        ObjectCache::clear();
        // A registration form of an event with accounts holds the account block (owner ruling 2026-11-18).
        $content ??= $placement === 'registration'
            ? self::ACCOUNT_BLOCK . $this->slotFields()
            : $this->field('text', 'megjegyzes');

        return $this->insertForm($content, ['kalaka_event_id' => $event, 'kalaka_placement' => $placement]);
    }

    /** Closes the registration window of the form's event: it closed long ago. */
    protected function closeRegistration(int $form): void
    {
        global $wpdb;
        $event = (int) get_post_meta($form, 'kalaka_event_id', true);
        $wpdb->update(
            $wpdb->prefix . 'kalaka_events',
            ['registration_closes_at' => '2000-01-01 00:00:00.000000'],
            ['id' => $event]
        );
        ObjectCache::clear();
    }

    /** A published page placing the form as its block, which `SignUp::page()` finds: its permalink. */
    protected function placed(int $form): string
    {
        if (!isset($this->pages[$form])) {
            $page = self::factory()->post->create([
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_content' => '<!-- wp:kalaka/form {"formId":' . $form . '} /-->',
            ]);
            $this->pages[$form] = (string) get_permalink($page);
        }

        return $this->pages[$form];
    }

    /** @param array<string, mixed> $answers */
    protected function submit(
        int $form,
        ?Actor $actor = null,
        array $answers = self::ANSWERS,
        ?string $token = null,
    ): SubmitResult {
        $version = $this->forms->form($form)?->version ?? 1;

        return (new RegistrationSubmit($this->runner))->submit(
            new SubmitRequest($form, $version, $answers, $token ?? SubmitToken::generate(), [], false),
            $actor ?? new GuestActor()
        );
    }

    protected function ownerOf(SubmitResult $result): ?int
    {
        return (new Registrations())->find($result->registration)?->ownerUserId;
    }

    /** @return list<array<string, ?string>> every delivery row, in id order */
    protected function deliveries(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}kalaka_deliveries ORDER BY id", ARRAY_A);
        self::assertIsArray($rows);

        return $rows;
    }

    /** @return list<array<string, ?string>> the account mails among the deliveries */
    protected function accountMails(): array
    {
        return array_values(array_filter(
            $this->deliveries(),
            static fn (array $row): bool => str_starts_with((string) $row['mail'], 'account_')
        ));
    }

    /** A user that signed up on the registration page: Kaláka's flag, so unverified. */
    protected function unverifiedUser(string $email = 'teszt.anna@example.org'): int
    {
        $id = self::factory()->user->create(['user_email' => $email, 'role' => 'subscriber']);
        update_user_meta($id, SignUpPage::UNVERIFIED, '1');

        return $id;
    }

    protected function verifiedUser(string $email = 'teszt.csilla@example.org'): int
    {
        return self::factory()->user->create(['user_email' => $email, 'role' => 'subscriber']);
    }
}
