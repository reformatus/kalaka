<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Events\CreateEvent;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\SeatAct;
use Kalaka\Registrations\Api\Seats;
use Kalaka\Registrations\Api\SeatsFull;
use Kalaka\Tests\Integration\Foundation\TestItemRepository;

/**
 * A pool before the registration row in the lock order (a quota row, as a price tier's or an offer's will be)
 * under the real lock guard of the runner: an act on an existing registration locks the pool first through
 * `Seats::lock()`, then the registration, then takes the seat.
 */
final class SeatsQuotaTest extends RegistrationsTestCase
{
    private Seats $seats;

    private int $event;

    private int $row;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        TestItemRepository::create('kalaka_test_items');
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_test_items', ['label' => 'Támogatott', 'version' => 1]);
        $this->row = (int) $wpdb->insert_id;
        $this->seats = new Seats();
        $this->event = (int) $this->runner->run(new CreateEvent(), ['name' => 'Tábor'], new CliActor(null));
        $this->insertRegistration($this->newRegistration($this->event, '00A0'));
        $this->insertRegistration($this->newRegistration($this->event, '00A2'));
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test setup; the statement is built from the table prefix and a constant only
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}kalaka_test_items");

        parent::tear_down();
    }

    private function pool(?int $limit): TestQuotaPool
    {
        return new TestQuotaPool($this->event, 'tier.tamogatott', 'kalaka_test_items', $this->row, $limit);
    }

    /** An act on an existing registration: the quota pool first, then the registration, then the work. */
    private function act(string $code, TestQuotaPool $pool, string $method, SeatAct $act = SeatAct::Admission): mixed
    {
        return $this->inRun(function (array $input, Context $context) use ($code, $pool, $method, $act): mixed {
            $key = new RegistrationKey($this->event, $code);
            $this->seats->lock($pool, $context);
            $this->registrations->findForUpdate($key, $context);

            return $this->seats->$method($pool, $key, $act, $context);
        });
    }

    public function testAnActThatLocksThePoolBeforeTheRegistrationTakesItsSeat(): void
    {
        $pool = $this->pool(1);

        self::assertTrue($this->act('00A0', $pool, 'takeIfFree'));

        self::assertSame(1, $this->seats->held($pool));
        self::assertTrue($this->seats->holds($pool, new RegistrationKey($this->event, '00A0')));
    }

    public function testAFullQuotaPoolRefusesATakeAndGivesNoSeatToATakeIfFree(): void
    {
        $pool = $this->pool(1);
        $this->act('00A0', $pool, 'takeIfFree');

        self::assertFalse($this->act('00A2', $pool, 'takeIfFree', SeatAct::Restore));
        try {
            $this->act('00A2', $pool, 'take');
            self::fail('A full pool should refuse.');
        } catch (SeatsFull) {
            self::assertSame(1, $this->seats->held($pool));
        }
        $this->act('00A2', $pool, 'takeOver', SeatAct::Placement);
        self::assertSame(1, $this->seats->over($pool));
    }

    public function testATakeAfterTheRegistrationIsLockedFailsTheRunAndTakesNothing(): void
    {
        $pool = $this->pool(2);

        try {
            $this->inRun(function (array $input, Context $context) use ($pool): void {
                $key = new RegistrationKey($this->event, '00A0');
                $this->registrations->findForUpdate($key, $context);
                $this->seats->takeIfFree($pool, $key, SeatAct::Admission, $context);
            });
            self::fail('The lock order should have refused the pool row after the registration row.');
        } catch (SystemFailure) {
            self::assertSame(0, $this->seats->held($pool));
        }
    }
}
