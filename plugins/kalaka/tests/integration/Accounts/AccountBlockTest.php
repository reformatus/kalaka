<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Accounts;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Events\Api\Events;
use Kalaka\Forms\AccountBlock;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Api\SignUp;
use Kalaka\Forms\BrokenForms;
use Kalaka\Forms\BrokenFormsWarning;
use Kalaka\Forms\DefinitionCheck;
use Kalaka\Forms\Engine\AccountMarkup;
use Kalaka\Forms\Engine\BlockGate;
use Kalaka\Forms\Engine\FormPage;
use Kalaka\Forms\Engine\FormPageView;
use Kalaka\Forms\Engine\Rest\FormRoutes;
use Kalaka\Forms\EventFormHost;
use Kalaka\Forms\Module;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * The account block (owner ruling 2026-11-18): the save check of its presence, the editor's gate, the re-fetch
 * that lets the whole form through and gates „Tovább” on the block's page in `mandatory` mode, the block
 * re-rendered after a login in its window, and the condition subject `account.logged_in`.
 */
final class AccountBlockTest extends AccountsTestCase
{
    /** @param array<string, mixed> $attributes */
    private function page(string $inner, array $attributes = []): string
    {
        return '<!-- wp:kalaka/form-page' . ($attributes === [] ? '' : ' ' . wp_json_encode($attributes)) . ' -->'
            . $inner . '<!-- /wp:kalaka/form-page -->';
    }

    /** @return array<string, mixed> */
    private function when(string $key, string $value): array
    {
        return ['match' => 'all', 'items' => [['key' => $key, 'operator' => 'equals', 'value' => $value]]];
    }

    /** Two pages: the block and the slot's fields on the first, a text field on the second. */
    private function twoPages(string $first = self::ACCOUNT_BLOCK): string
    {
        return $this->page($first . $this->slotFields()) . $this->page($this->field('text', 'megjegyzes'));
    }

    /** Whether a page check refuses for the account (the slot's empty fields refuse too; they do not count here). */
    private function gated(int $form, int $page, array $answers = []): bool
    {
        $errors = $this->refetch($form, $page, $answers)['availability']['errors'];

        return array_key_exists(AccountMarkup::ERROR_KEY, $errors);
    }

    /**
     * @param array<string, mixed> $more
     * @return array<string, mixed>
     */
    private function refetch(int $form, ?int $page = null, array $answers = [], array $more = []): array
    {
        $body = ['version' => $this->readForm($form)->version, 'answers' => $answers] + $more;
        if ($page !== null) {
            $body['page'] = $page;
        }
        $request = new \WP_REST_Request('POST', '/' . FormRoutes::NAMESPACE . '/forms/' . $form . '/availability');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
        $response = rest_do_request($request);
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame(200, $response->get_status(), (string) wp_json_encode($data));

        return json_decode((string) wp_json_encode($data), true);
    }

    public function testTheSaveCheckWantsTheBlockOnALiveRegistrationFormOfAnEventWithAccounts(): void
    {
        $missing = [AccountBlock::MISSING, SignUp::BLOCK];
        foreach (['mandatory' => $missing, 'optional' => $missing, 'off' => null] as $mode => $problem) {
            $form = $this->openForm($mode);
            $event = $this->readForm($form)->eventId;
            $check = static fn (string $content, FormPlacement $placement = FormPlacement::Registration): ?array
                => DefinitionCheck::problem($content, $form, $placement, $event);

            self::assertSame($problem, $check($this->slotFields()), $mode);
            self::assertNull($check(self::ACCOUNT_BLOCK . $this->slotFields()), $mode);
            // Anywhere in the form's body: on a later page, or in a branch.
            self::assertNull($check($this->page($this->slotFields()) . $this->page(self::ACCOUNT_BLOCK)), $mode);
            // Not in the after-submit section, which is not the form.
            $after = '<!-- wp:kalaka/after-submit -->' . self::ACCOUNT_BLOCK . '<!-- /wp:kalaka/after-submit -->';
            self::assertSame($problem, $check($this->slotFields() . $after), $mode);
            // Additional forms never need it.
            self::assertNull($check($this->field('text', 'megjegyzes'), FormPlacement::Additional), $mode);
        }
    }

    private function open(int $form): FormPageView
    {
        return (new FormPage(new EventFormHost()))->open($form);
    }

    /** Whether the form's page shows its form (a closed one throws the failure the block turns into its text). */
    private function opens(int $form): bool
    {
        try {
            return $this->open($form)->content !== null;
        } catch (SystemFailure) {
            return false;
        }
    }

    /**
     * B1 (#191 review 2): the block's presence is checked at use too, with the event's CURRENT mode. A published
     * form without it, once its event is switched to accounts, is closed and named in the site warning like any
     * other broken form; saving it with the block reopens it; the mode back to off is fine again.
     */
    public function testAnEventSwitchedToAccountsClosesItsPublishedFormsWithoutTheBlock(): void
    {
        $form = $this->openForm('off', 'registration', $this->slotFields());
        $event = $this->readForm($form)->eventId;
        $line = '#' . $form . ' Regisztráció: account_block_missing kalaka/account';
        $closed = function () use ($form): ?array {
            try {
                $this->forms->definition($this->readForm($form));
            } catch (SystemFailure $e) {
                return DefinitionCheck::split($e);
            }

            return null;
        };

        self::assertNull($closed());
        self::assertSame([], BrokenForms::compute());

        foreach (['optional', 'mandatory'] as $mode) {
            $this->setMode($event, $mode);
            self::assertSame([AccountBlock::MISSING, SignUp::BLOCK], $closed(), $mode);
            self::assertSame([$line], BrokenForms::compute(), $mode);
            BrokenForms::refresh();
            self::assertSame($line, (new BrokenFormsWarning())->check()?->details, $mode);
            // The form's page and its re-fetch stay closed: no form, no answer is taken.
            self::assertFalse($this->opens($form), $mode);

            $this->setMode($event, 'off');
            self::assertNull($closed(), $mode);
            self::assertSame([], BrokenForms::compute(), $mode);
        }

        // Adding the block and saving reopens it, per the existing rules.
        $this->setMode($event, 'mandatory');
        (new Module())->registerMeta();
        $author = get_userdata(self::factory()->user->create());
        self::assertInstanceOf(\WP_User::class, $author);
        $author->add_cap(AdminAccess::FULL_ACCESS);
        wp_set_current_user($author->ID);
        $saved = wp_update_post(['ID' => $form, 'post_content' => self::ACCOUNT_BLOCK . $this->slotFields()], true);
        self::assertSame($form, $saved);
        wp_set_current_user(0);

        self::assertNull($closed());
        self::assertSame([], BrokenForms::compute());
        self::assertTrue($this->opens($form));
        // An earlier version is read as it was: the version without the block still parses.
        $revisions = $this->revisionIds($form);
        self::assertNotEmpty($revisions);
        self::assertSame($form, $this->forms->definition($this->readForm($form), $revisions[0])->formId);

        // An additional form never needs it, whatever the mode.
        $additional = $this->openForm('mandatory', 'additional');
        self::assertSame([], BrokenForms::compute());
        self::assertSame($additional, $this->forms->definition($this->readForm($additional))->formId);
    }

    /**
     * B2 (#191 review 2): the logged-in account's address reaches the contact email through the host's prefill
     * seam (`FormHost::prefill()`), the page's only prefill path; the re-fetch's block gives the same value.
     */
    public function testTheAccountsEmailComesThroughTheHostsPrefill(): void
    {
        $form = $this->openForm('optional');
        $host = new EventFormHost();
        $read = $this->readForm($form);

        self::assertSame([], $host->prefill($read));
        self::assertStringNotContainsString('value="teszt.csilla@example.org"', (string) $this->open($form)->content);

        wp_set_current_user($this->verifiedUser());
        self::assertSame(['email' => 'teszt.csilla@example.org'], $host->prefill($read));
        self::assertMatchesRegularExpression(
            '#name="email"[^>]* value="teszt.csilla@example.org"#',
            (string) $this->open($form)->content
        );
        // The re-fetch's block gives what the host gives.
        $answer = $this->refetch($form, null, [], ['account' => $this->placed($form)]);
        self::assertSame($host->prefill($read), $answer['account']['filled']);

        // Only on a form with an account step: none in `off` mode and on an additional form.
        self::assertSame([], $host->prefill($this->readForm($this->openForm('off'))));
        self::assertSame([], $host->prefill($this->readForm($this->openForm('optional', 'additional'))));
        // Nothing of an account with no address.
        wp_get_current_user()->user_email = '';
        self::assertSame([], $host->prefill($read));
    }

    public function testARestSaveWithoutTheBlockIsRefusedWithTheGenericMessage(): void
    {
        $form = $this->openForm('optional');
        (new \Kalaka\Forms\Module())->registerMeta();
        $author = get_userdata(self::factory()->user->create());
        self::assertInstanceOf(\WP_User::class, $author);
        $author->add_cap(\Kalaka\Admin\Api\AdminAccess::FULL_ACCESS);
        wp_set_current_user($author->ID);

        $request = new \WP_REST_Request('POST', '/wp/v2/kalaka_form/' . $form);
        $request->set_param('content', $this->slotFields());
        $request->set_param('status', 'publish');
        $response = rest_do_request($request);

        self::assertSame(400, $response->get_status());
        $data = $response->get_data();
        self::assertIsArray($data);
        self::assertSame(
            'Az űrlapot így nem lehet menteni: account_block_missing („kalaka/account”).',
            $data['message']
        );
    }

    public function testTheEditorOffersTheBlockOnlyWhereTheFormCanHaveAccounts(): void
    {
        $allowed = static function (int $form): array {
            $list = BlockGate::allowed(true, 'kalaka_form', 'core/edit-post', Contributions::current());
            $list = AccountBlock::allowed($list, new \WP_Block_Editor_Context(['post' => get_post($form)]));
            self::assertIsArray($list);

            return $list;
        };

        self::assertContains(SignUp::BLOCK, $allowed($this->openForm('mandatory')));
        self::assertContains(SignUp::BLOCK, $allowed($this->openForm('optional')));
        self::assertNotContains(SignUp::BLOCK, $allowed($this->openForm('off')));
        self::assertNotContains(SignUp::BLOCK, $allowed($this->openForm('mandatory', 'additional')));
        // Not known yet (a new form without its settings): offered.
        self::assertContains(SignUp::BLOCK, $allowed(self::factory()->post->create(['post_type' => 'kalaka_form'])));
        // Another editor keeps what it had.
        $page = self::factory()->post->create(['post_type' => 'page']);
        self::assertTrue(AccountBlock::allowed(true, new \WP_Block_Editor_Context(['post' => get_post($page)])));
        // Elsewhere it is gated like the other form-only blocks.
        self::assertContains(SignUp::BLOCK, BlockGate::FORM_ONLY_BLOCKS);
    }

    public function testTheRefetchShowsAGuestTheMandatoryFormAndGatesOnlyTheBlocksPage(): void
    {
        $form = $this->openForm('mandatory', 'registration', $this->twoPages());

        $plain = $this->refetch($form);
        self::assertSame([], $plain['availability']['errors']);
        self::assertSame(
            [AccountMarkup::ERROR_KEY => AccountMarkup::ERROR],
            $this->refetch($form, 0, self::ANSWERS)['availability']['errors']
        );
        self::assertFalse($this->gated($form, 1));

        // Logged in, or in optional mode: no error.
        wp_set_current_user($this->verifiedUser());
        self::assertFalse($this->gated($form, 0));
        wp_set_current_user(0);
        $optional = $this->openForm('optional', 'registration', $this->twoPages());
        self::assertFalse($this->gated($optional, 0));

        // The block on page 1: page 0 passes; it gates page 1.
        $later = $this->openForm(
            'mandatory',
            'registration',
            $this->page($this->slotFields()) . $this->page(self::ACCOUNT_BLOCK . $this->field('text', 'megjegyzes'))
                . $this->page($this->field('text', 'utolso'))
        );
        self::assertFalse($this->gated($later, 0));
        self::assertTrue($this->gated($later, 1));
    }

    /** Validation follows what is shown: the block in a branch that does not hold gates nothing. */
    public function testTheBlockInABranchThatDoesNotHoldGatesNothing(): void
    {
        $choice = $this->field('choice', 'fiok', ['options' => [
            ['value' => 'igen', 'label' => 'Igen'],
            ['value' => 'nem', 'label' => 'Nem'],
        ]]);
        $branch = '<!-- wp:kalaka/if ' . wp_json_encode(['condition' => $this->when('fiok', 'igen')]) . ' -->'
            . self::ACCOUNT_BLOCK . '<!-- /wp:kalaka/if -->';
        $form = $this->openForm('mandatory', 'registration', $this->twoPages($choice . $branch));

        self::assertFalse($this->gated($form, 0, ['fiok' => 'nem']));
        self::assertTrue($this->gated($form, 0, ['fiok' => 'igen']));
    }

    public function testTheRefetchReRendersTheBlockAndGivesTheContactEmailAfterALogin(): void
    {
        $form = $this->openForm('optional');
        $page = $this->placed($form);

        $guest = $this->refetch($form, null, [], ['account' => $page]);
        self::assertStringContainsString('data-kalaka-account="out"', $guest['account']['html']);
        self::assertSame([], $guest['account']['filled']);

        wp_set_current_user($this->verifiedUser());
        $in = $this->refetch($form, null, [], ['account' => $page]);
        self::assertStringContainsString('Bejelentkezve: teszt.csilla@example.org.', $in['account']['html']);
        // The logout returns to the form's page found on the server; a foreign address only picks among them.
        self::assertStringContainsString(esc_url(wp_logout_url($page)), $in['account']['html']);
        self::assertSame(['email' => 'teszt.csilla@example.org'], $in['account']['filled']);
        $foreign = $this->refetch($form, null, [], ['account' => 'https://evil.example.com/']);
        self::assertStringContainsString(esc_url(wp_logout_url($page)), $foreign['account']['html']);

        // Without `account`, or for a form without accounts, no block in the answer.
        self::assertArrayNotHasKey('account', $this->refetch($form));
        self::assertArrayNotHasKey('account', $this->refetch($this->openForm('off'), null, [], ['account' => $page]));
    }

    /**
     * Owner ruling 2026-11-18: true, false and null are separate. `account.logged_in` is yes or no
     * only on a form with an account step; on an off event's form and on an additional form it is none, so a branch
     * on `equals no` never shows to a logged-in person there.
     */
    public function testLoggedInIsNoneWhereTheFormHasNoAccountStep(): void
    {
        $chain = '<!-- wp:kalaka/if ' . wp_json_encode(['condition' => $this->when(SignUp::LOGGED_IN, 'no')]) . ' -->'
            . $this->field('text', 'megjegyzes')
            . '<!-- /wp:kalaka/if -->';
        $optional = $this->openForm('optional', 'registration', self::ACCOUNT_BLOCK . $this->slotFields() . $chain);
        $mandatory = $this->openForm('mandatory', 'registration', self::ACCOUNT_BLOCK . $this->slotFields() . $chain);
        $off = $this->openForm('off', 'registration', $this->slotFields() . $chain);
        $additional = $this->openForm('optional', 'additional', $chain);

        // Where the step exists: no for a guest, yes (the branch hidden) for an account.
        foreach ([$optional, $mandatory] as $form) {
            self::assertSame([0 => 0], $this->refetch($form)['availability']['chains']);
        }
        // Where it does not: none for a guest and for a logged-in person alike.
        foreach ([$off, $additional] as $form) {
            self::assertSame([0 => null], $this->refetch($form)['availability']['chains']);
        }
        wp_set_current_user($this->verifiedUser());
        foreach ([$optional, $mandatory, $off, $additional] as $form) {
            self::assertSame([0 => null], $this->refetch($form)['availability']['chains']);
        }

        $event = (new Events())->find((int) get_post_meta($optional, 'kalaka_event_id', true));
        self::assertNotNull($event);
        self::assertSame(['yes'], EventValues::form($event, true)[SignUp::LOGGED_IN]->data);
        self::assertSame(['no'], EventValues::form($event, false)[SignUp::LOGGED_IN]->data);
        self::assertTrue(EventValues::form($event, null)[SignUp::LOGGED_IN]->isEmpty());
    }

    /** The subject „Bejelentkezve”: a chain on it ships both branches and follows the login. */
    public function testLoggedInIsAConditionSubjectThatChangesInPlace(): void
    {
        $chain = '<!-- wp:kalaka/if ' . wp_json_encode(['condition' => $this->when(SignUp::LOGGED_IN, 'yes')]) . ' -->'
            . $this->field('consent', 'emlekezteto', ['label' => 'Emlékeztető'])
            . '<!-- /wp:kalaka/if -->';
        $form = $this->openForm('optional', 'registration', self::ACCOUNT_BLOCK . $this->slotFields() . $chain);

        // Both branches ship: the chain is not withheld.
        $definition = $this->forms->definition($this->readForm($form));
        self::assertSame([], $definition->withheldChains());

        self::assertSame([0 => null], $this->refetch($form)['availability']['chains']);
        wp_set_current_user($this->verifiedUser());
        self::assertSame([0 => 0], $this->refetch($form)['availability']['chains']);

        // In off mode it is none, even logged in.
        $off = $this->openForm('off', 'registration', $this->slotFields() . $chain);
        self::assertSame([0 => null], $this->refetch($off)['availability']['chains']);
        wp_set_current_user(0);

        // The submit judges it as the actor: a guest's consent in the hidden branch is dropped.
        $answers = self::ANSWERS + ['emlekezteto' => true];
        $guest = $this->submit($form, null, $answers);
        $account = $this->submit($form, new AccountActor($this->verifiedUser('teszt.dora@example.org')), [
            'name' => 'Teszt Dóra',
            'email' => 'teszt.dora@example.org',
            'emlekezteto' => true,
        ]);
        self::assertNull($this->ownerOf($guest));
        self::assertNotNull($this->ownerOf($account));
    }
}
