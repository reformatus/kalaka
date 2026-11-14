<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Admin\FullAccessOnActivation;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Tests\Integration\Foundation\AuditTestCase;

/** Activating the plugin gives the activating user full access, but only while nobody holds it. */
final class FullAccessOnActivationTest extends AuditTestCase
{
    private Runner $production;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->production = Runner::current();
        Runner::publish($this->runner);
        // Whoever holds full access in the shared test site is left out of these cases.
        foreach (get_users(['capability' => AdminAccess::FULL_ACCESS, 'fields' => 'ID']) as $id) {
            $user = get_userdata((int) $id);
            if ($user instanceof \WP_User) {
                $user->remove_cap(AdminAccess::FULL_ACCESS);
            }
        }
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        Runner::publish($this->production);

        parent::tear_down();
    }

    public function testTheActivatingUserGetsFullAccessAsTheirOwnCapabilityAudited(): void
    {
        $user = self::factory()->user->create(['role' => 'editor']);

        FullAccessOnActivation::grant($user);

        $wpUser = get_userdata($user);
        self::assertInstanceOf(\WP_User::class, $wpUser);
        self::assertTrue($wpUser->has_cap(AdminAccess::FULL_ACCESS));
        self::assertTrue($wpUser->caps[AdminAccess::FULL_ACCESS] ?? false);
        self::assertFalse((bool) get_role('editor')?->has_cap(AdminAccess::FULL_ACCESS));
        $entry = $this->onlyEntry();
        self::assertSame('system', $entry['actor_type']);
        self::assertSame(['user//' . $user], $this->subjectsOf((string) $entry['id']));
    }

    public function testNobodyGetsItWhileSomeoneHoldsIt(): void
    {
        $holder = get_userdata(self::factory()->user->create());
        self::assertInstanceOf(\WP_User::class, $holder);
        $holder->add_cap(AdminAccess::FULL_ACCESS);
        $user = self::factory()->user->create();

        FullAccessOnActivation::grant($user);

        self::assertFalse(user_can($user, AdminAccess::FULL_ACCESS));
        self::assertSame([], $this->entries());
    }

    public function testNobodyGetsItWhileARoleCarriesItForSomeone(): void
    {
        add_role('kalaka_teszt_role', 'Teszt szerep', [AdminAccess::FULL_ACCESS => true]);
        try {
            self::factory()->user->create(['role' => 'kalaka_teszt_role']);
            $user = self::factory()->user->create();

            FullAccessOnActivation::grant($user);

            self::assertFalse(user_can($user, AdminAccess::FULL_ACCESS));
            self::assertSame([], $this->entries());
        } finally {
            remove_role('kalaka_teszt_role');
        }
    }

    public function testNoUserNoGrant(): void
    {
        FullAccessOnActivation::grant(0);

        self::assertSame([], $this->entries());
    }
}
