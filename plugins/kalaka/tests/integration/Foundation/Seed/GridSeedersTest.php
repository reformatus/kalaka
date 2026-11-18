<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Seed;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\Cli\AdminSeeder;
use Kalaka\Admin\View\ViewList;
use Kalaka\Events\Api\Events;
use Kalaka\Forms\Cli\FormSeeder;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\Cli\RegistrationSeeder;

/** S37: the seed gives the admin grid sample registrations, a view with a parameter and a grant holder on it. */
final class GridSeedersTest extends SeedTestCase
{
    private Runner $production;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        // The registration seeder submits as the form page does, through the production runner.
        $this->production = Runner::current();
        Runner::publish($this->runner);
        $this->contributions->seeder(new FormSeeder());
        $this->contributions->seeder(new RegistrationSeeder());
        $this->contributions->seeder(new AdminSeeder());
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        Runner::publish($this->production);
        Access::forget();

        parent::tear_down();
    }

    public function testTheSampleEventGetsRegistrationsAViewAndAGrantHolder(): void
    {
        global $wpdb;
        $this->seeded();
        $event = (new Events())->published()[0]->id;

        $statuses = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) AS n FROM {$wpdb->prefix}kalaka_registrations WHERE event_id = %d"
            . ' GROUP BY status ORDER BY status',
            $event
        ), ARRAY_A);
        self::assertSame(
            [['status' => 'registered', 'n' => '40'], ['status' => 'waitlisted', 'n' => '6']],
            $statuses
        );
        $keys = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT field_key FROM {$wpdb->prefix}kalaka_field_values WHERE event_id = %d ORDER BY field_key",
            $event
        ));
        self::assertSame(
            ['ebed', 'ejszakak', 'email', 'fenykep', 'megjegyzes', 'nev', 'programok', 'szallas'],
            $keys
        );

        $views = (new ViewList())->ofEvent($event);
        self::assertSame(['Ebédlista'], array_column($views, 'name'));
        self::assertStringContainsString('"parameter":true', $views[0]['filter']);

        $user = get_user_by('login', AdminSeeder::GRANTEE_LOGIN);
        self::assertInstanceOf(\WP_User::class, $user);
        self::assertSame(['kalaka_grantee'], $user->roles);
        $held = (new Access())->heldViews(new UserActor($user->ID), $event);
        self::assertCount(1, $held);
        self::assertSame($views[0]['id'], $held[0]->viewId);
        self::assertSame('vegetarianus', $held[0]->bound());
    }
}
