<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Periodic;

use Kalaka\Foundation\Module;
use Kalaka\Foundation\Periodic\PeriodicRun;
use WP_UnitTestCase;

/** Foundation's boot wiring of the periodic run (the WP-CLI branch needs WP-CLI, which phpunit does not load). */
final class PeriodicBootTest extends WP_UnitTestCase
{
    public function testBootSchedulesTheRunAndHandsBothHooksTheProductionRun(): void
    {
        remove_all_actions('kalaka_periodic_run');
        remove_all_actions('kalaka_periodic_run_now');
        wp_unschedule_hook('kalaka_periodic_run');
        delete_option(PeriodicRun::LAST_RUN_OPTION);

        (new Module())->boot();

        $event = wp_get_scheduled_event('kalaka_periodic_run');
        self::assertIsObject($event);
        self::assertSame('kalaka_five_minutes', $event->schedule);
        self::assertNotFalse(has_action('kalaka_periodic_run'));
        self::assertNotFalse(has_action('kalaka_periodic_run_now'));

        do_action('kalaka_periodic_run_now');

        self::assertIsString(get_option(PeriodicRun::LAST_RUN_OPTION));
        self::assertFalse(get_option('kalaka_periodic_run.lock'));
    }
}
