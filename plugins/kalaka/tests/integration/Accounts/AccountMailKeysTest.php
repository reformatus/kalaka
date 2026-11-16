<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Accounts;

use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Mail\Body\AccountLinkRule;
use Kalaka\Mail\Body\BodyCheck;
use Kalaka\Mail\Body\MailRenderer;
use Kalaka\Mail\Body\MailValues;
use Kalaka\Registrations\Account\SignUpPage;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Mail\MailBodyTestCase;

/**
 * S15-10 (owner ruling 2026-11-16): no separate set-password mail. The link travels inside "Jelentkezés
 * rögzítve" as `account.password_link`, in a branch on `account.unverified`; in an event with accounts that
 * mail's template must read the key and the mail stays on.
 */
final class AccountMailKeysTest extends MailBodyTestCase
{
    private const OWNER_EMAIL = 'teszt@example.test';

    private function event(string $mode): int
    {
        $event = $this->published();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_events', ['account_mode' => $mode], ['id' => $event]);
        ObjectCache::clear();

        return $event;
    }

    private function own(RegistrationKey $key, int $user): void
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'kalaka_registrations',
            ['owner_user_id' => $user],
            ['event_id' => $key->eventId, 'code' => $key->code]
        );
        ObjectCache::clear();
    }

    private function user(bool $unverified, string $email = self::OWNER_EMAIL): int
    {
        $id = self::factory()->user->create(['user_email' => $email, 'role' => 'subscriber']);
        if ($unverified) {
            update_user_meta($id, SignUpPage::UNVERIFIED, '1');
        }

        return $id;
    }

    /** The body an author writes: the link inside a branch on `account.unverified`. */
    private function withLink(): string
    {
        return $this->paragraph('Köszönjük!') . "\n" . $this->branch(
            'if',
            $this->paragraph('Jelszó: <span data-kalaka-value="account.password_link">link</span>'),
            ['match' => 'all', 'items' => [['key' => 'account.unverified', 'operator' => 'empty', 'not' => true]]]
        );
    }

    private function render(int $event, RegistrationKey $key): string
    {
        return (new MailRenderer())->render($event, 'registered', $key)->html;
    }

    public function testAnUnverifiedOwnerGetsAFreshLinkWordPressAccepts(): void
    {
        $event = $this->event('mandatory');
        $this->switchOn($event, 'registered', $this->document($event, $this->withLink()));
        $key = $this->registration($event);
        $user = get_userdata($this->user(true));
        $this->own($key, $user->ID);

        $html = $this->render($event, $key);

        self::assertSame(1, preg_match('/Jelszó: (\S+action=rp\S+)</', html_entity_decode($html), $match));
        parse_str((string) wp_parse_url($match[1], PHP_URL_QUERY), $query);
        self::assertSame($user->user_login, $query['login']);
        self::assertInstanceOf(\WP_User::class, check_password_reset_key((string) $query['key'], $user->user_login));

        // The newest link wins: rendering again makes a new key, and the first stops working.
        $this->render($event, $key);
        self::assertInstanceOf(\WP_Error::class, check_password_reset_key((string) $query['key'], $user->user_login));
    }

    public function testAGuestAVerifiedOwnerOrAnotherAddressReadsNone(): void
    {
        $event = $this->event('optional');
        $this->switchOn($event, 'registered', $this->document($event, $this->withLink()));
        $guest = $this->registration($event, '12A3');
        $verified = $this->registration($event, '34B5');
        $this->own($verified, $this->user(false, 'teszt.csilla@example.test'));
        $other = $this->registration(
            $event,
            '56C7',
            ['name' => 'Teszt Gábor', 'email' => 'teszt.gabor@example.test', 'camps' => '["ff"]']
        );
        $ferenc = $this->user(true, 'teszt.ferenc@example.test');
        $this->own($other, $ferenc);

        foreach ([$guest, $verified, $other] as $key) {
            $html = $this->render($event, $key);
            self::assertStringNotContainsString('Jelszó', $html, $key->code);
            self::assertStringContainsString('Köszönjük!', $html);
        }
        // No key was made for an account whose link would go to another address.
        self::assertSame('', get_userdata($ferenc)->user_activation_key);
    }

    public function testABodyWithoutTheKeyMakesNoLink(): void
    {
        $event = $this->event('off');
        $this->switchOn($event, 'registered', $this->document($event, $this->paragraph('Köszönjük!')));
        $key = $this->registration($event);
        $user = $this->user(true);
        $this->own($key, $user);

        $this->render($event, $key);

        self::assertSame('', get_userdata($user)->user_activation_key);
    }

    public function testTheKeyIsFoundAsAnInlineValueOrABinding(): void
    {
        self::assertTrue(MailValues::readsPasswordLink('<span data-kalaka-value="account.password_link">x</span>'));
        $binding = '{"url":{"source":"kalaka/value","args":{"key":"account.password_link"}}}';
        $button = '<!-- wp:button {"metadata":{"bindings":' . $binding . '}} /-->';
        self::assertTrue(MailValues::readsPasswordLink($button));
        self::assertFalse(MailValues::readsPasswordLink('<p>account.password_link</p>'));
        self::assertFalse(MailValues::readsPasswordLink('<span data-kalaka-value="registration.link">x</span>'));
    }

    public function testInAnEventWithAccountsTheMailNeedsTheKeyAndStaysOn(): void
    {
        $event = $this->event('mandatory');
        $without = $this->document($event, $this->paragraph('Köszönjük!'));

        try {
            $this->switchOn($event, 'registered', $without);
            self::fail('A template without the link must be refused.');
        } catch (RuleViolation $refused) {
            self::assertSame(AccountLinkRule::NEEDS_LINK, $refused->getMessage());
        }

        $with = $this->document($event, $this->withLink(), 'Jelentkezés');
        $this->switchOn($event, 'registered', $with);
        try {
            $this->updateMail(['event_id' => $event, 'mail' => 'registered', 'enabled' => false]);
            self::fail('Switching the mail off must be refused.');
        } catch (RuleViolation $refused) {
            self::assertSame(AccountLinkRule::NO_SWITCH_OFF, $refused->getMessage());
        }

        // Another mail needs nothing, and in mode off the rule does not hold.
        $this->switchOn($event, 'waitlisted', $without);
        $off = $this->event('off');
        $this->switchOn($off, 'registered', $this->document($off, $this->paragraph('Köszönjük!')));
        $this->updateMail(['event_id' => $off, 'mail' => 'registered', 'enabled' => false]);
    }

    public function testSavingTheTemplateWithoutTheKeyIsRefused(): void
    {
        $event = $this->event('optional');
        $body = $this->document($event, $this->withLink());
        $this->switchOn($event, 'registered', $body);

        self::assertSame(AccountLinkRule::NEEDS_LINK, (new AccountLinkRule())->problem(
            $body,
            $this->paragraph('Köszönjük!'),
            $event
        ));
        self::assertNull((new AccountLinkRule())->problem($body, $this->withLink(), $event));

        // Any other save path: the graceful veto keeps the published content.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        wp_update_post(['ID' => $body, 'post_content' => $this->paragraph('Köszönjük!')]);
        self::assertTrue(MailValues::readsPasswordLink((string) get_post($body)?->post_content));
        self::assertSame(AccountLinkRule::NEEDS_LINK, get_transient(BodyCheck::NOTICE . get_current_user_id()));
    }
}
