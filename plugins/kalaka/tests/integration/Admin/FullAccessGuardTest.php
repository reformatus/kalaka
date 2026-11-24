<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Admin\FullAccess\FullAccessQuery;
use Kalaka\Admin\FullAccess\LastHolderGuard;
use Kalaka\Admin\FullAccess\ProfileField;
use Kalaka\Admin\FullAccess\UsersColumn;

/**
 * D4 and D3: full access is the user's own capability, never a role's, and WordPress cannot delete its
 * last holder from the users screen, REST or `wp user delete` (foundation.md, "Views and grants").
 */
final class FullAccessGuardTest extends AdminTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        // Only this test's admin holds full access.
        foreach (get_users(['fields' => 'ID']) as $id) {
            if ((int) $id !== $this->admin->userId) {
                (new \WP_User((int) $id))->remove_cap(AdminAccess::FULL_ACCESS);
            }
        }
        Access::forget();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        wp_set_current_user(0);
        Access::forget();

        parent::tear_down();
    }

    /** @return array<string, mixed> */
    private function viewInput(int $event): array
    {
        return [
            'event_id' => $event,
            'name' => 'Nézet',
            'filter' => [],
            'columns' => [['column' => 'code', 'write' => false]],
        ];
    }

    public function testARoleCarryingTheCapabilityGivesItsMemberNothingAndTheirOwnCapabilityStillWorks(): void
    {
        $event = $this->createEvent();
        add_role('kalaka_teszt_role', 'Teszt szerep', [AdminAccess::FULL_ACCESS => true]);
        try {
            $member = self::factory()->user->create(['role' => 'kalaka_teszt_role']);

            self::assertSame([$this->admin->userId], (new FullAccessQuery())->holders());
            self::assertFalse(user_can($member, AdminAccess::FULL_ACCESS));
            self::assertFalse(AdminAccess::userIsFull($member));
            self::assertFalse(Access::mayReachAdmin($member));

            wp_set_current_user($member);
            self::assertFalse(wp_get_ability('kalaka/save-view')->check_permissions($this->viewInput($event)));
            $request = new \WP_REST_Request('POST', '/wp-abilities/v1/abilities/kalaka/save-view/run');
            $request->set_header('content-type', 'application/json');
            $request->set_body((string) wp_json_encode(['input' => $this->viewInput($event)]));
            self::assertSame(403, rest_do_request($request)->get_status());

            // The same user with the capability as their own: the rule is about where it comes from.
            (new \WP_User($member))->add_cap(AdminAccess::FULL_ACCESS);
            Access::forget();
            self::assertTrue(user_can($member, AdminAccess::FULL_ACCESS));
            self::assertTrue(AdminAccess::userIsFull($member));
            self::assertTrue(wp_get_ability('kalaka/save-view')->check_permissions($this->viewInput($event)));
            self::assertContains($member, (new FullAccessQuery())->holders());
        } finally {
            remove_role('kalaka_teszt_role');
        }
    }

    public function testTheProfileAndTheUsersColumnHoldTheSameRuleAsTheCapabilityCheck(): void
    {
        $other = self::factory()->user->create(['role' => 'subscriber']);
        // Another plugin wrote a truthy value that is not the boolean true.
        update_user_meta($other, $GLOBALS['wpdb']->get_blog_prefix() . 'capabilities', [AdminAccess::FULL_ACCESS => 1]);
        $user = new \WP_User($other);

        self::assertFalse(user_can($user, AdminAccess::FULL_ACCESS));
        self::assertNotContains($other, (new FullAccessQuery())->holders());
        self::assertFalse(ProfileField::holds($user));
        self::assertSame('', UsersColumn::cell('', 'kalaka', $other));

        (new \WP_User($other))->add_cap(AdminAccess::FULL_ACCESS);
        self::assertTrue(user_can($other, AdminAccess::FULL_ACCESS));
        self::assertTrue(ProfileField::holds(new \WP_User($other)));
        self::assertNotSame('', UsersColumn::cell('', 'kalaka', $other));
    }

    public function testWordPressCannotDeleteTheOnlyHolderButCanWhenAnotherRemains(): void
    {
        $administrator = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($administrator);

        self::assertFalse(current_user_can('delete_user', $this->admin->userId));
        self::assertFalse(current_user_can('remove_user', $this->admin->userId));

        $request = new \WP_REST_Request('DELETE', '/wp/v2/users/' . $this->admin->userId);
        $request->set_param('force', true);
        $request->set_param('reassign', false);
        self::assertSame(403, rest_do_request($request)->get_status());
        self::assertInstanceOf(\WP_User::class, get_userdata($this->admin->userId));

        (new \WP_User(self::factory()->user->create()))->add_cap(AdminAccess::FULL_ACCESS);
        self::assertTrue(current_user_can('delete_user', $this->admin->userId));

        $request = new \WP_REST_Request('DELETE', '/wp/v2/users/' . $this->admin->userId);
        $request->set_param('force', true);
        $request->set_param('reassign', false);
        self::assertSame(200, rest_do_request($request)->get_status());
        self::assertFalse(get_userdata($this->admin->userId));
    }

    public function testWithNoHolderTheDeletionIsAllowedAndWritesNoLogLine(): void
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        $administrator = self::factory()->user->create(['role' => 'administrator']);
        $target = self::factory()->user->create();
        wp_set_current_user($administrator);
        (new \WP_User($this->admin->userId))->remove_cap(AdminAccess::FULL_ACCESS);
        Access::forget();

        self::assertSame([], (new FullAccessQuery())->holders());
        self::assertTrue(current_user_can('delete_user', $target));

        $log = $this->capturedErrorLog(static function () use ($target): void {
            self::assertTrue(wp_delete_user($target));
        });

        self::assertFalse(get_userdata($target));
        self::assertSame('', trim($log));
    }

    public function testTheOnlyHolderCheckOnlyUsesAUserIdArgument(): void
    {
        $id = $this->admin->userId;

        self::assertSame([], LastHolderGuard::metaCaps([], 'delete_user', 0, []));
        self::assertSame([], LastHolderGuard::metaCaps([], 'delete_user', 0, [0]));
        self::assertSame([], LastHolderGuard::metaCaps([], 'delete_user', 0, ['nem-szam']));
        self::assertSame(['do_not_allow'], LastHolderGuard::metaCaps([], 'delete_user', 0, [(string) $id]));
        self::assertSame(['do_not_allow'], LastHolderGuard::metaCaps([], 'delete_user', 0, [$id]));
        self::assertSame([], LastHolderGuard::metaCaps([], 'edit_user', 0, [$id]));
        self::assertSame([], LastHolderGuard::metaCaps([], 'delete_users', 0, []));
    }

    public function testPlainCodeDeletingTheLastHolderIsLoggedAndProceeds(): void
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        $id = $this->admin->userId;

        $log = $this->capturedErrorLog(static function () use ($id): void {
            self::assertTrue(wp_delete_user($id));
        });

        self::assertFalse(get_userdata($id));
        $lines = array_values(array_filter(
            explode("\n", $log),
            static fn (string $line): bool => str_contains(
                $line,
                'guarding the last user with full access from deletion'
            )
        ));
        self::assertCount(1, $lines);
        self::assertStringContainsString(
            'Kaláka: guarding the last user with full access from deletion failed',
            $lines[0]
        );
        self::assertStringContainsString(
            'The last user with full access was deleted past the capability check; nobody holds full access now.',
            $lines[0]
        );
    }

    public function testTheCommandLineStopsTheDeletionOfTheOnlyHolder(): void
    {
        require_once __DIR__ . '/../Foundation/Seed/WpCliDouble.php';
        if (!defined('WP_CLI::IS_TEST_DOUBLE')) {
            self::markTestSkipped('The real WP-CLI is loaded; the recording double is not in use.');
        }
        \WP_CLI::reset();
        $id = $this->admin->userId;

        try {
            LastHolderGuard::deleting($id, true);
            self::fail('The command line should have refused to delete the only holder.');
        } catch (\RuntimeException $e) {
            self::assertSame('WP_CLI::error', $e->getMessage());
        }

        self::assertSame(
            [['error', [
                'Cannot delete the last user with full access to Kaláka. Give full access to another user first.',
                1,
            ]]],
            \WP_CLI::$calls
        );
        self::assertInstanceOf(\WP_User::class, get_userdata($id));
    }

    public function testTheCommandLineDeletesAUserWhenAnotherHolderRemains(): void
    {
        require_once __DIR__ . '/../Foundation/Seed/WpCliDouble.php';
        if (!defined('WP_CLI::IS_TEST_DOUBLE')) {
            self::markTestSkipped('The real WP-CLI is loaded; the recording double is not in use.');
        }
        \WP_CLI::reset();
        (new \WP_User(self::factory()->user->create()))->add_cap(AdminAccess::FULL_ACCESS);

        LastHolderGuard::deleting($this->admin->userId, true);

        self::assertSame([], \WP_CLI::$calls);
    }
}
