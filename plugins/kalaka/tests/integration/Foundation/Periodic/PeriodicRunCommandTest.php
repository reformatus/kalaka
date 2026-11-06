<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Periodic;

use Kalaka\Foundation\Cli\PeriodicRunCommand;

require_once __DIR__ . '/PeriodicTestCase.php';
require_once __DIR__ . '/../Seed/WpCliDouble.php';

/** AC9: the command's wiring; the real command is run in wp-env. */
final class PeriodicRunCommandTest extends PeriodicTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        if (!defined('WP_CLI::IS_TEST_DOUBLE')) {
            self::markTestSkipped('The real WP-CLI is loaded; the recording double is not in use.');
        }
        \WP_CLI::reset();
    }

    public function testItPrintsTheReportAsOneLineAndSucceeds(): void
    {
        $event = $this->eventIn('published');
        $this->seedFlag($event, '00A0');
        $this->timeRules->add(SpyTimeRule::naming('00B0'));

        (new PeriodicRunCommand($this->periodicRun()))([], []);

        self::assertSame([['success', 'Evaluated 2 registrations, 0 failed; 0 time rule checks failed.']], \WP_CLI::$calls);
    }

    public function testAHeldLockIsAnErrorWithExitCodeOneAndNothingIsEvaluated(): void
    {
        $this->eventIn('published');
        $spy = SpyTimeRule::naming('00A0');
        $this->timeRules->add($spy);
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        \WP_Upgrader::create_lock('kalaka_periodic_run', 900);

        try {
            (new PeriodicRunCommand($this->periodicRun()))([], []);
            self::fail('The command should have ended in an error.');
        } catch (\RuntimeException $e) {
            self::assertSame('WP_CLI::error', $e->getMessage());
        }

        self::assertSame(1, \WP_CLI::$calls[0][1][1]);
        self::assertSame('error', \WP_CLI::$calls[0][0]);
        self::assertCount(1, \WP_CLI::$calls);
        self::assertSame([], $spy->inputs);
        self::assertSame([], $this->evaluationEntries());
    }

    public function testAClosedSiteIsAnError(): void
    {
        $this->closeTheSite();

        try {
            (new PeriodicRunCommand($this->periodicRun()))([], []);
            self::fail('The command should have ended in an error.');
        } catch (\RuntimeException) {
            self::assertSame('error', \WP_CLI::$calls[0][0]);
        }
    }
}
