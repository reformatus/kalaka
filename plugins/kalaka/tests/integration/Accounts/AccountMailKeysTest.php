<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Accounts;

use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Api\SignUp;
use Kalaka\Forms\BrokenForms;
use Kalaka\Forms\Engine\FormPage;
use Kalaka\Forms\EventFormHost;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\SystemFailure;
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
        $this->setMode($event, $mode);

        return $event;
    }

    /** Changes an event's account mode as a write around the use cases does (there is no act yet). */
    private function setMode(int $event, string $mode): void
    {
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_events', ['account_mode' => $mode], ['id' => $event]);
        ObjectCache::clear();
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

    /** The body an author writes: the link inside a branch on `account.unverified` equal to yes. */
    private function withLink(): string
    {
        return $this->paragraph('Köszönjük!') . "\n" . $this->branch(
            'if',
            $this->paragraph('Jelszó: <span data-kalaka-value="account.password_link">link</span>'),
            ['match' => 'all', 'items' => [['key' => 'account.unverified', 'operator' => 'equals', 'value' => 'yes']]]
        );
    }

    /** A body with one branch on `account.unverified`: its text shows when the condition holds. */
    private function branched(string $operator, ?string $value = null): string
    {
        $item = ['key' => 'account.unverified', 'operator' => $operator];
        if ($value !== null) {
            $item['value'] = $value;
        }

        return $this->branch('if', $this->paragraph('Szakasz'), ['match' => 'all', 'items' => [$item]]);
    }

    private function showsBranch(int $event, RegistrationKey $key, string $body): bool
    {
        $this->switchOn($event, 'registered', $this->document($event, $this->withLink() . "\n" . $body));

        return str_contains($this->render($event, $key), 'Szakasz');
    }

    /** Owner ruling 2026-11-18: true, false and null are separate; `empty` is none only, `equals no` is no only. */
    public function testUnverifiedIsYesNoOrNoneAndEachBranchReadsItsOwn(): void
    {
        $event = $this->event('optional');
        $unverified = $this->registration($event, '12A3');
        $this->own($unverified, $this->user(true));
        $verified = $this->registration(
            $event,
            '34B4',
            ['name' => 'Teszt Csilla', 'email' => 'teszt.csilla@example.test', 'camps' => '["ff"]']
        );
        $this->own($verified, $this->user(false, 'teszt.csilla@example.test'));
        $guest = $this->registration($event, '56B4');
        $other = $this->registration(
            $event,
            '00A0',
            ['name' => 'Teszt Gábor', 'email' => 'teszt.gabor@example.test', 'camps' => '["ff"]']
        );
        $this->own($other, $this->user(true, 'teszt.ferenc@example.test'));
        $off = $this->event('off');
        $offKey = $this->registration($off, '00B0');

        // An owner who is the recipient: yes or no. Everyone else: none.
        $expected = [
            ['equals', 'yes', [true, false, false, false]],
            ['equals', 'no', [false, true, false, false]],
            ['empty', null, [false, false, true, true]],
        ];
        foreach ($expected as [$operator, $value, $shows]) {
            $body = $this->branched($operator, $value);
            foreach ([$unverified, $verified, $guest, $other] as $i => $key) {
                self::assertSame($shows[$i], $this->showsBranch($event, $key, $body), "$operator $value $key->code");
            }
        }
        // An event without accounts has no owner: none.
        self::assertTrue($this->showsBranch($off, $offKey, $this->branched('empty')));
        self::assertFalse($this->showsBranch($off, $offKey, $this->branched('equals', 'no')));
    }

    /**
     * Master's default 2026-11-18 (Q1), the same rule as R4: no account step, no account facts. An owned
     * registration of an event switched to `off` reads `account.unverified` as none, whoever owns it.
     */
    public function testUnverifiedIsNoneWhenTheEventHasNoAccountStep(): void
    {
        $event = $this->event('optional');
        $key = $this->registration($event);
        $this->own($key, $this->user(true));
        self::assertTrue($this->showsBranch($event, $key, $this->branched('equals', 'yes')));

        global $wpdb;
        $wpdb->update($wpdb->prefix . 'kalaka_events', ['account_mode' => 'off'], ['id' => $event]);
        ObjectCache::clear();

        self::assertFalse($this->showsBranch($event, $key, $this->branched('equals', 'yes')));
        self::assertFalse($this->showsBranch($event, $key, $this->branched('equals', 'no')));
        self::assertTrue($this->showsBranch($event, $key, $this->branched('empty')));
    }

    /**
     * C1-1 (#191 review 3): an event switched to accounts closes the live form that lacks the account block,
     * and the form is named in the site warning. A registration submitted on that current version still reads
     * its recipient and its context, and its mail still renders: a stored submission is never read as a form
     * being served.
     */
    public function testAModeChangeClosesTheFormWithoutCostingARegistrationItsMail(): void
    {
        $event = $this->event('off');
        $form = $this->slotForm($event, false);
        $key = $this->registration(
            $event,
            '12A3',
            ['name' => 'Teszt Elek', 'email' => 'teszt@example.test'],
            'registered',
            $form
        );
        $this->switchOn($event, 'registered', $this->document($event, $this->withLink()));

        $this->setMode($event, 'mandatory');

        // The form being served is closed and named, like any broken form.
        self::assertSame(
            ['#' . $form . ' Jelentkezés: account_block_missing kalaka/account'],
            BrokenForms::compute()
        );
        $this->expectSystemFailure(static fn () => (new FormPage(new EventFormHost()))->open($form));

        // The registration on the current version keeps its recipient and context, and its mail renders.
        $values = new MailValues();
        self::assertSame('teszt@example.test', $values->recipient($key));
        self::assertSame('teszt@example.test', $values->context($key)->get(MailValues::RECIPIENT_EMAIL)->text);
        self::assertSame('Teszt Elek', $values->context($key)->get(MailValues::RECIPIENT_NAME)->text);
        self::assertStringContainsString('Köszönjük!', $this->render($event, $key));
    }

    /** A serving read that must fail: the page's own read of the broken form. */
    private function expectSystemFailure(callable $read): void
    {
        try {
            $read();
        } catch (SystemFailure) {
            return;
        }

        self::fail('The form being served must stay closed.');
    }

    /** Q2: both facts' yes and no read „Igen” and „Nem”, the approved texts of the participant prompt's buttons. */
    public function testTheYesAndNoValuesReadIgenAndNem(): void
    {
        $event = $this->event('optional');
        $unverified = $this->registration($event, '12A3');
        $this->own($unverified, $this->user(true));
        $verified = $this->registration(
            $event,
            '34B4',
            ['name' => 'Teszt Csilla', 'email' => 'teszt.csilla@example.test', 'camps' => '["ff"]']
        );
        $this->own($verified, $this->user(false, 'teszt.csilla@example.test'));
        $inline = $this->paragraph('Fiók: <span data-kalaka-value="account.unverified">x</span>.');
        $this->switchOn($event, 'registered', $this->document($event, $this->withLink() . "\n" . $inline));

        self::assertMatchesRegularExpression('#Fiók: <span[^>]*>Igen</span>#', $this->render($event, $unverified));
        self::assertMatchesRegularExpression('#Fiók: <span[^>]*>Nem</span>#', $this->render($event, $verified));

        $loggedIn = $this->event('optional');
        $eventRecord = (new Events())->find($loggedIn);
        self::assertNotNull($eventRecord);
        self::assertSame('Igen', EventValues::form($eventRecord, true)[SignUp::LOGGED_IN]->text);
        self::assertSame('Nem', EventValues::form($eventRecord, false)[SignUp::LOGGED_IN]->text);
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

        $pattern = '/Jelszó: <span[^>]*>([^<]+action=rp[^<]+)</';
        self::assertSame(1, preg_match($pattern, html_entity_decode($html), $match));
        parse_str((string) wp_parse_url($match[1], PHP_URL_QUERY), $query);
        self::assertSame($user->user_login, $query['login']);
        // OP1: the link carries the registration's form, so the login after the reset returns to it.
        $formId = SignUp::formIdOf($query[SignUp::MARKER] ?? null);
        self::assertNotNull($formId);
        self::assertSame($event, (new Forms())->form($formId)?->eventId);
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
        $verified = $this->registration($event, '34B4');
        $this->own($verified, $this->user(false, 'teszt.csilla@example.test'));
        $other = $this->registration(
            $event,
            '56B4',
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
        $condition = '{"condition":{"match":"all","items":[{"key":"account.password_link","operator":"empty"}]}}';
        $conditionOnly = '<!-- wp:kalaka/if ' . $condition . ' --><p>x</p><!-- /wp:kalaka/if -->';
        self::assertFalse(MailValues::readsPasswordLink($conditionOnly));
        $nested = '<!-- wp:kalaka/if ' . $condition . ' -->' . $button . '<!-- /wp:kalaka/if -->';
        self::assertTrue(MailValues::readsPasswordLink($nested));
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
            self::assertStringEndsWith(' ' . AccountLinkRule::NEEDS_LINK, $refused->getMessage());
        }

        $with = $this->document($event, $this->withLink(), 'Jelentkezés');
        $this->switchOn($event, 'registered', $with);
        try {
            $this->updateMail(['event_id' => $event, 'mail' => 'registered', 'enabled' => false]);
            self::fail('Switching the mail off must be refused.');
        } catch (RuleViolation $refused) {
            self::assertStringEndsWith(' ' . AccountLinkRule::NO_SWITCH_OFF, $refused->getMessage());
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
