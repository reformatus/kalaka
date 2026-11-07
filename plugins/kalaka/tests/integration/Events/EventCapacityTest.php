<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Events;

use Kalaka\Events\Api\EventCapacity;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Registration;
use Kalaka\Registrations\RegistrationRepository;
use Kalaka\Registrations\SecretLink;
use Kalaka\Tests\Integration\Foundation\AdminProbe;

/** AC10, apart from the second connection's view of the lock; and the lock-order guard around the event row. */
final class EventCapacityTest extends EventsTestCase
{
    /** @param \Closure(Context, EventCapacity): mixed $work */
    private function inRun(\Closure $work): mixed
    {
        return $this->runner->run(
            new AdminProbe(fn (array $input, Context $context): mixed => $work($context, new EventCapacity())),
            [],
            new CliActor(null)
        );
    }

    public function testLockForRegistrationReturnsTheEventWithItsCapacityAndLatch(): void
    {
        $id = $this->filled();

        $event = $this->inRun(
            fn (Context $context, EventCapacity $capacity) => $capacity->lockForRegistration($context, $id)
        );

        self::assertNotNull($event);
        self::assertSame($id, $event->id);
        self::assertSame(41, $event->capacity);
        self::assertFalse($event->isFull);
    }

    public function testLockForRegistrationAfterARegistrationLockIsALockOrderFailure(): void
    {
        $id = $this->filled();

        $this->expectException(SystemFailure::class);
        $this->inRun(function (Context $context, EventCapacity $capacity) use ($id): void {
            $context->lock(LockLevel::Registration);
            $capacity->lockForRegistration($context, $id);
        });
    }

    public function testLockForRegistrationAfterARegistrationInsertIsASystemFailureAndLeavesNothing(): void
    {
        $id = $this->filled();
        $registrations = new RegistrationRepository();
        $before = $this->registrationCount();

        try {
            $this->inRun(function (Context $context, EventCapacity $capacity) use ($id, $registrations): void {
                $registrations->upsert(self::newRegistration($id, '1A23'), $context);
                $capacity->lockForRegistration($context, $id);
            });
            self::fail('The run should have failed.');
        } catch (SystemFailure) {
            self::assertSame($before, $this->registrationCount());
        }
    }

    public function testTheSubmitOrderPassesTheGuard(): void
    {
        $id = $this->filled();
        $registrations = new RegistrationRepository();

        $this->inRun(function (Context $context, EventCapacity $capacity) use ($id, $registrations): void {
            $capacity->lockForRegistration($context, $id);
            $registrations->upsert(self::newRegistration($id, '1A23'), $context);
            $capacity->markFull($context, $id);
        });

        self::assertSame('1', $this->row($id)['is_full']);
        self::assertSame('1', $this->registrationRow($id, '1A23')['version'] ?? null);
    }

    private static function newRegistration(int $event, string $code): Registration
    {
        $now = new \DateTimeImmutable('2026-11-30 08:00:00', new \DateTimeZone('UTC'));

        return new Registration(
            new RegistrationKey($event, $code),
            RegistrationStatus::Registered,
            SecretLink::generate(),
            $now,
            $now
        );
    }

    private function registrationCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_registrations");
    }

    /** @return ?array<string, ?string> */
    private function registrationRow(int $event, string $code): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}kalaka_registrations WHERE event_id = %d AND code = %s",
                $event,
                $code
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function testAMissingEventIsNotFound(): void
    {
        $id = $this->filled();

        $this->expectException(NotFound::class);
        $this->inRun(
            fn (Context $context, EventCapacity $capacity) => $capacity->lockForRegistration($context, $id + 1000)
        );
    }

    public function testMarkFullOfAMissingEventIsNotFound(): void
    {
        $id = $this->filled();

        $this->expectException(NotFound::class);
        $this->inRun(fn (Context $context, EventCapacity $capacity) => $capacity->markFull($context, $id + 1000));
    }

    public function testMarkFullSetsTheLatchAndTheEntryListsTheChange(): void
    {
        $id = $this->filled();
        $version = $this->versionOf($id);

        $this->inRun(function (Context $context, EventCapacity $capacity) use ($id): void {
            $capacity->lockForRegistration($context, $id);
            $capacity->markFull($context, $id);
        });

        self::assertSame('1', $this->row($id)['is_full']);
        self::assertSame($version + 1, $this->versionOf($id));
        $entry = $this->entriesOf('kalaka/probe')[0];
        self::assertSame(
            [
                [
                    'type' => 'event',
                    'event' => $id,
                    'key' => (string) $id,
                    'field' => 'is_full',
                    'before' => '0',
                    'after' => '1',
                ],
            ],
            $this->changesOf($entry)
        );
        self::assertSame(["event/{$id}/{$id}"], $this->subjectsOf($entry['id']));
    }

    public function testMarkFullTwiceInOneRunChangesOnceAndRunsNoSecondStatement(): void
    {
        global $wpdb;
        $id = $this->filled();
        $version = $this->versionOf($id);
        $second = null;

        $this->inRun(function (Context $context, EventCapacity $capacity) use ($id, $wpdb, &$second): void {
            $capacity->lockForRegistration($context, $id);
            $capacity->markFull($context, $id);
            $before = $wpdb->num_queries;
            $capacity->markFull($context, $id);
            $second = $wpdb->num_queries - $before;
        });

        self::assertSame(0, $second);
        self::assertSame($version + 1, $this->versionOf($id));
        self::assertCount(1, $this->changesOf($this->entriesOf('kalaka/probe')[0]));
    }
}
