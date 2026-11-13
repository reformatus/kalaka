<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Admin\GrantFullAccess;
use Kalaka\Admin\FullAccess\FullAccessQuery;
use Kalaka\Admin\FullAccess\ProfileField;
use Kalaka\Admin\FullAccess\RevokeFullAccess;
use Kalaka\Admin\FullAccess\Texts;
use Kalaka\Admin\FullAccess\UsersColumn;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\UseCase\Runner;

/**
 * S36-1 (owner ruling 2026-11-10): full access per user, given and taken through audited use cases, the
 * last holder kept, the profile checkbox and the Users column (activation: FullAccessOnActivationTest).
 */
final class FullAccessTest extends AdminTestCase
{
    private Runner $production;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        // Only this test's users hold full access.
        foreach (get_users(['fields' => 'ID']) as $id) {
            if ((int) $id !== $this->admin->userId) {
                (new \WP_User((int) $id))->remove_cap(AdminAccess::FULL_ACCESS);
            }
        }
        $this->production = Runner::current();
        Runner::publish($this->runner);
        Access::forget();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        Runner::publish($this->production);
        wp_set_current_user(0);
        $_POST = [];
        Access::forget();

        parent::tear_down();
    }

    private static function holds(int $userId): bool
    {
        $user = new \WP_User($userId);

        return !empty($user->caps[AdminAccess::FULL_ACCESS]);
    }

    public function testGrantingGivesThePerUserCapabilityAfterTheCommitAndIsAudited(): void
    {
        $user = self::factory()->user->create(['role' => 'editor']);

        $this->exec(new GrantFullAccess(), ['user_id' => $user]);

        self::assertTrue(self::holds($user));
        self::assertTrue(user_can($user, AdminAccess::FULL_ACCESS));
        self::assertSame(['editor'], (new \WP_User($user))->roles);
        $entry = $this->onlyEntry();
        self::assertSame('kalaka/grant-full-access', $entry['action']);
        self::assertSame('succeeded', $entry['outcome']);
        self::assertSame(["user//{$user}"], $this->subjectsOf($entry['id']));
        self::assertSame(
            [['type' => 'user', 'event' => null, 'key' => (string) $user, 'field' => AdminAccess::FULL_ACCESS,
                'before' => false, 'after' => true]],
            json_decode((string) $entry['changes'], true)
        );
    }

    public function testRevokingAuditsTheRealOldValue(): void
    {
        $user = $this->fullAccessUser();

        $this->exec(new RevokeFullAccess(), ['user_id' => $user]);

        self::assertFalse(self::holds($user));
        self::assertSame(
            [['type' => 'user', 'event' => null, 'key' => (string) $user, 'field' => AdminAccess::FULL_ACCESS,
                'before' => true, 'after' => false]],
            json_decode((string) $this->onlyEntry()['changes'], true)
        );
    }

    public function testGrantingToAHolderOrRevokingFromANonHolderChangesNothing(): void
    {
        $user = self::factory()->user->create();

        $this->exec(new GrantFullAccess(), ['user_id' => $this->admin->userId]);
        $this->exec(new RevokeFullAccess(), ['user_id' => $user]);

        self::assertTrue(self::holds($this->admin->userId));
        self::assertFalse(self::holds($user));
        foreach ($this->entries() as $entry) {
            self::assertSame('succeeded', $entry['outcome']);
            self::assertStringNotContainsString(AdminAccess::FULL_ACCESS, (string) $entry['changes']);
        }
    }

    public function testRevokingTakesItWhenAnotherHolderRemains(): void
    {
        $other = $this->fullAccessUser();

        $this->exec(new RevokeFullAccess(), ['user_id' => $this->admin->userId], new UserActor($other));

        self::assertFalse(self::holds($this->admin->userId));
        self::assertTrue(self::holds($other));
    }

    public function testTheLastHolderCannotLoseItNotEvenByThemselvesOrTheCommandLine(): void
    {
        foreach ([$this->admin, new \Kalaka\Foundation\Api\Actor\CliActor(null), new SystemActor()] as $actor) {
            try {
                $this->exec(new RevokeFullAccess(), ['user_id' => $this->admin->userId], $actor);
                self::fail('The last holder should have kept full access.');
            } catch (RuleViolation $e) {
                self::assertSame('A művelet nem hajtható végre. ' . Texts::LAST_HOLDER, $e->getMessage());
            }
            self::assertTrue(self::holds($this->admin->userId));
        }
        foreach ($this->entries() as $entry) {
            self::assertSame('failed', $entry['outcome']);
        }
    }

    public function testAUserHoldingItThroughARoleCountsAsAHolderForTheLastHolderCheck(): void
    {
        add_role('kalaka_teszt_role', 'Teszt szerep', [AdminAccess::FULL_ACCESS => true]);
        try {
            $other = self::factory()->user->create(['role' => 'kalaka_teszt_role']);
            self::assertSame([$this->admin->userId, $other], (new FullAccessQuery())->holders());

            $this->exec(new RevokeFullAccess(), ['user_id' => $this->admin->userId], new UserActor($other));

            self::assertFalse(self::holds($this->admin->userId));
        } finally {
            remove_role('kalaka_teszt_role');
        }
    }

    public function testAnAdministratorWithoutFullAccessCannotGrantIt(): void
    {
        $administrator = self::factory()->user->create(['role' => 'administrator']);

        $this->expectException(PermissionDenied::class);
        try {
            $this->exec(new GrantFullAccess(), ['user_id' => $administrator], new UserActor($administrator));
        } finally {
            self::assertFalse(self::holds($administrator));
        }
    }

    public function testAUserThatDoesNotExistIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->exec(new GrantFullAccess(), ['user_id' => 999999]);
    }

    public function testChangingTheUsersRoleKeepsTheCapability(): void
    {
        $user = self::factory()->user->create(['role' => 'editor']);
        $this->exec(new GrantFullAccess(), ['user_id' => $user]);

        (new \WP_User($user))->set_role('subscriber');
        (new \WP_User($user))->set_role('kalaka_grantee');

        self::assertTrue(self::holds($user));
    }

    private function profile(int $userId): string
    {
        ob_start();
        ProfileField::render(new \WP_User($userId));

        return (string) ob_get_clean();
    }

    /**
     * Saves the profile or user-edit form as `wp-admin/user-edit.php` does: first `personal_options_update`
     * (one's own profile) or `edit_user_profile_update`, then `edit_user()`, which checks the form, fires
     * `user_profile_update_errors` and saves the user (`profile_update`). The user's own e-mail address and
     * nickname are posted unless `$fields` says otherwise.
     *
     * @param array<string, string> $fields
     */
    private function submit(int $userId, array $fields): int|\WP_Error
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        $user = new \WP_User($userId);
        $_POST = $fields + [
            '_wpnonce' => wp_create_nonce('update-user_' . $userId),
            'kalaka_full_access_shown' => '1',
            'email' => $user->user_email,
            'nickname' => $user->nickname !== '' ? $user->nickname : $user->user_login,
        ];

        do_action($userId === get_current_user_id() ? 'personal_options_update' : 'edit_user_profile_update', $userId);

        return edit_user($userId);
    }

    /** An administrator with full access, who may open other users' edit screens. */
    private function granter(): int
    {
        $granter = self::factory()->user->create(['role' => 'administrator']);
        (new \WP_User($granter))->add_cap(AdminAccess::FULL_ACCESS);

        return $granter;
    }

    public function testTheCheckboxShowsOnlyToAUserWithFullAccess(): void
    {
        $user = self::factory()->user->create(['role' => 'editor']);
        $administrator = self::factory()->user->create(['role' => 'administrator']);

        wp_set_current_user($administrator);
        self::assertSame('', $this->profile($user));

        wp_set_current_user($this->admin->userId);
        $html = $this->profile($user);
        self::assertStringContainsString('<h2>' . Texts::SECTION . '</h2>', $html);
        self::assertStringContainsString(esc_html(Texts::CHECKBOX), $html);
        self::assertStringNotContainsString('checked', $html);
        self::assertStringContainsString('checked="checked"', $this->profile($this->admin->userId));
    }

    public function testAFormCoreRefusesGivesNothingAndShowsCoresError(): void
    {
        $user = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($this->granter());

        $result = $this->submit($user, ['email' => 'not-an-email', 'kalaka_full_access' => '1']);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertContains('invalid_email', $result->get_error_codes());
        self::assertNotContains('kalaka_full_access', $result->get_error_codes());
        self::assertFalse(self::holds($user));
        self::assertNotContains('kalaka/grant-full-access', array_column($this->entries(), 'action'));
    }

    public function testAValidFormWithTheBoxTickedGivesItAsTheCurrentUserAfterCoreSaved(): void
    {
        $user = self::factory()->user->create(['role' => 'editor']);
        $granter = $this->granter();
        wp_set_current_user($granter);

        $result = $this->submit($user, ['kalaka_full_access' => '1', 'first_name' => 'Teszt']);

        self::assertSame($user, $result);
        self::assertTrue(self::holds($user));
        self::assertSame('Teszt', (new \WP_User($user))->first_name);
        $entry = $this->onlyEntry();
        self::assertSame('kalaka/grant-full-access', $entry['action']);
        self::assertSame('user', $entry['actor_type']);
        self::assertSame((string) $granter, (string) $entry['actor_user_id']);
    }

    public function testAnUnchangedBoxRunsNothingAndAnUntickedBoxTakesIt(): void
    {
        $user = $this->fullAccessUser();
        wp_set_current_user($this->granter());

        self::assertSame($user, $this->submit($user, ['kalaka_full_access' => '1']));
        self::assertSame([], $this->entries());

        self::assertSame($user, $this->submit($user, []));
        self::assertFalse(self::holds($user));
        self::assertSame('kalaka/revoke-full-access', $this->onlyEntry()['action']);
    }

    public function testTheLastHolderUntickingTheirOwnBoxIsRefusedAndTheBoxShowsTickedAgain(): void
    {
        wp_set_current_user($this->admin->userId);
        $before = (new \WP_User($this->admin->userId))->first_name;

        $result = $this->submit($this->admin->userId, ['first_name' => 'Más']);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame(
            [esc_html('A művelet nem hajtható végre. ' . Texts::LAST_HOLDER)],
            $result->get_error_messages('kalaka_full_access')
        );
        self::assertTrue(self::holds($this->admin->userId));
        self::assertSame($before, (new \WP_User($this->admin->userId))->first_name);
        self::assertSame([], $this->entries());
        self::assertStringContainsString('checked="checked"', $this->profile($this->admin->userId));
    }

    public function testTheRefusalOnOnesOwnProfileKeepsCoresEmailError(): void
    {
        wp_set_current_user($this->admin->userId);
        $hooked = has_action('personal_options_update', 'send_confirmation_on_profile_email');
        if ($hooked === false) {
            add_action('personal_options_update', 'send_confirmation_on_profile_email');
        }
        try {
            $result = $this->submit($this->admin->userId, ['email' => 'not-an-email']);
        } finally {
            if ($hooked === false) {
                remove_action('personal_options_update', 'send_confirmation_on_profile_email');
            }
            unset($GLOBALS['errors']);
        }

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertContains('user_email', $result->get_error_codes());
        self::assertContains('kalaka_full_access', $result->get_error_codes());
        self::assertTrue(self::holds($this->admin->userId));
    }

    public function testSavingAUserElsewhereRunsNothing(): void
    {
        $user = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($this->granter());
        $this->post($user, ['kalaka_full_access' => '1']);

        // A profile save that was never checked (another plugin's or WP-CLI's wp_update_user()).
        wp_update_user(['ID' => $user, 'first_name' => 'Teszt']);

        self::assertFalse(self::holds($user));
        self::assertSame([], $this->entries());
    }

    public function testAFormWithoutTheFieldOrFromAUserWithoutFullAccessOrWithoutANonceChangesNothing(): void
    {
        $user = $this->fullAccessUser();
        $administrator = self::factory()->user->create(['role' => 'administrator']);

        // The field was not on the form: an unticked box is not a revocation.
        wp_set_current_user($this->granter());
        self::assertSame($user, $this->submit($user, ['kalaka_full_access_shown' => '']));

        // A forged form from an administrator without full access.
        wp_set_current_user($administrator);
        self::assertSame($user, $this->submit($user, []));

        // No valid nonce.
        wp_set_current_user($this->granter());
        self::assertSame($user, $this->submit($user, ['_wpnonce' => 'nope']));

        self::assertTrue(self::holds($user));
        self::assertSame([], $this->entries());
    }

    /** @param array<string, string> $fields */
    private function post(int $userId, array $fields): void
    {
        $_POST = $fields + ['_wpnonce' => wp_create_nonce('update-user_' . $userId), 'kalaka_full_access_shown' => '1'];
    }

    public function testTheUsersColumnShowsWhoHoldsItToAUserWithFullAccessOnly(): void
    {
        $user = self::factory()->user->create();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        self::assertSame(['username' => 'Felhasználónév'], UsersColumn::columns(['username' => 'Felhasználónév']));

        wp_set_current_user($this->admin->userId);
        self::assertSame(
            ['username' => 'Felhasználónév', 'kalaka' => Texts::COLUMN],
            UsersColumn::columns(['username' => 'Felhasználónév'])
        );
        self::assertSame(Texts::CELL, UsersColumn::cell('', 'kalaka', $this->admin->userId));
        self::assertSame('', UsersColumn::cell('', 'kalaka', $user));
        self::assertSame('other', UsersColumn::cell('other', 'role', $user));
    }
}
