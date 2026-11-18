<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Accounts;

use Kalaka\Forms\AccountBlock;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Api\SignUp;
use Kalaka\Forms\DefinitionCheck;
use Kalaka\Forms\Engine\AccountMarkup;
use Kalaka\Forms\Engine\BlockGate;
use Kalaka\Forms\Engine\Rest\FormRoutes;
use Kalaka\Foundation\Api\Actor\AccountActor;
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

    /** [UNAPPROVED N30] The subject „Bejelentkezve”: a chain on it ships both branches and follows the login. */
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

        // In off mode it is `no` even logged in.
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
