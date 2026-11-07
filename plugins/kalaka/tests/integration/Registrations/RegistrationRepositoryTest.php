<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Foundation\Api\Error\RetryableFailure;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\ReferenceCodeGenerator;
use Kalaka\Registrations\Registration;
use Kalaka\Registrations\Submission\Submission;
use Kalaka\Tests\Integration\Foundation\AdminProbe;
use Kalaka\Foundation\Api\Actor\CliActor;

require_once __DIR__ . '/RegistrationsTestCase.php';

/** AC6 to AC8: the registration repository. */
final class RegistrationRepositoryTest extends RegistrationsTestCase
{
    public function testADuplicateCodeOnTheFirstAttemptIsRetriedWithANewOne(): void
    {
        $this->insertRegistration($this->newRegistration(5, '12A3'));
        $before = $this->registrationCount();
        $failure = null;
        $generator = new ReferenceCodeGenerator();

        $useCase = new AdminProbe(function (array $input, Context $context, int $call) use (&$failure, $generator): void {
            if ($call === 1) {
                try {
                    $this->registrations->upsert($this->newRegistration(5, '12A3'), $context);
                } catch (RetryableFailure $caught) {
                    $failure = $caught;
                    throw $caught;
                }
            }
            $this->registrations->upsert($this->newRegistration(5, $generator->generate(5)), $context);
        });
        $this->runner->run($useCase, [], new CliActor(null));

        self::assertInstanceOf(RetryableFailure::class, $failure);
        self::assertSame(2, $useCase->calls);
        self::assertSame($before + 1, $this->registrationCount());
    }

    public function testACodeThatIsAlwaysTakenEndsRetryableAfterTheRunnersRetriesAndStoresNothing(): void
    {
        $this->insertRegistration($this->newRegistration(5, '12A3'));
        $before = $this->registrationCount();

        $useCase = new AdminProbe(function (array $input, Context $context): void {
            $this->registrations->upsert($this->newRegistration(5, '12A3'), $context);
        });
        try {
            $this->runner->run($useCase, [], new CliActor(null));
            self::fail('The run should have failed.');
        } catch (RetryableFailure) {
            self::assertSame(4, $useCase->calls);
        }

        self::assertSame($before, $this->registrationCount());
    }

    public function testASecondSubmissionWithAnExistingKeyIsASystemFailureAndNotRetried(): void
    {
        $key = new RegistrationKey(5, '12A3');
        $submission = new Submission($key, 1, 10, 1, ['name' => 'Anna'], $this->utc('2026-11-30 08:00:00'));
        $this->inRun(fn (array $input, Context $context) => $this->submissions->insert($submission, $context));

        $useCase = new AdminProbe(function (array $input, Context $context) use ($submission): void {
            $this->submissions->insert($submission, $context);
        });
        try {
            $this->runner->run($useCase, [], new CliActor(null));
            self::fail('The run should have failed.');
        } catch (SystemFailure $caught) {
            self::assertSame(SystemFailure::class, $caught::class);
            self::assertSame(1, $useCase->calls);
        }
    }

    public function testTheInsertIsAuditedWithTheSecretMaskedAndItsKeyTouched(): void
    {
        $registration = $this->newRegistration(5, '12A3');
        $touched = null;

        $stored = $this->inRun(function (array $input, Context $context) use ($registration, &$touched): Registration {
            $stored = $this->registrations->upsert($registration, $context);
            $touched = $context->touchedRegistrations();

            return $stored;
        });

        self::assertInstanceOf(Registration::class, $stored);
        self::assertSame(1, $stored->version);
        self::assertEquals([new RegistrationKey(5, '12A3')], $touched);
        $row = $this->rowOf('kalaka_registrations', 5, '12A3');
        self::assertSame('1', $row['version'] ?? null);
        self::assertSame('registered', $row['status'] ?? null);
        self::assertSame('2026-11-30 08:00:00.000000', $row['created_at'] ?? null);
        self::assertSame('2026-11-30 08:00:00.000000', $row['registered_at'] ?? null);
        self::assertSame($registration->secret, $row['secret'] ?? null);

        $entry = $this->onlyEntry();
        self::assertSame(['registration/5/12A3'], $this->subjectsOf($entry['id']));
        self::assertSame(
            [
                ['type' => 'registration', 'event' => 5, 'key' => '12A3', 'field' => 'status', 'before' => null, 'after' => 'registered'],
                ['type' => 'registration', 'event' => 5, 'key' => '12A3', 'field' => 'secret', 'masked' => true],
                ['type' => 'registration', 'event' => 5, 'key' => '12A3', 'field' => 'created_at', 'before' => null, 'after' => '2026-11-30 08:00:00.000000'],
                ['type' => 'registration', 'event' => 5, 'key' => '12A3', 'field' => 'registered_at', 'before' => null, 'after' => '2026-11-30 08:00:00.000000'],
            ],
            $this->decode($entry['changes'])
        );

        global $wpdb;
        $subjects = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}kalaka_audit_subjects WHERE entry_id = " . (int) $entry['id'],
            ARRAY_A
        );
        self::assertStringNotContainsString($registration->secret, (string) json_encode([$entry, $subjects]));
    }

    public function testAStatusChangeBumpsTheVersionAndOldVersionsConflict(): void
    {
        $this->insertRegistration($this->newRegistration(5, '12A3', RegistrationStatus::Waitlisted));
        $key = new RegistrationKey(5, '12A3');
        $stale = $this->registrations->find($key);
        self::assertInstanceOf(Registration::class, $stale);
        self::assertNull($stale->registeredAt);
        $entriesBefore = count($this->entries());

        $stored = $this->inRun(function (array $input, Context $context) use ($key): Registration {
            $registration = $this->registrations->findForUpdate($key, $context);
            self::assertInstanceOf(Registration::class, $registration);
            $registration->status = RegistrationStatus::Registered;
            $registration->registeredAt = $this->utc('2026-12-01 09:30:00.123456');

            return $this->registrations->upsert($registration, $context);
        });

        self::assertInstanceOf(Registration::class, $stored);
        self::assertSame(2, $stored->version);
        self::assertSame(RegistrationStatus::Registered, $stored->status);
        $entries = $this->entries();
        self::assertCount($entriesBefore + 1, $entries);
        self::assertSame(
            [
                ['type' => 'registration', 'event' => 5, 'key' => '12A3', 'field' => 'status', 'before' => 'waitlisted', 'after' => 'registered'],
                ['type' => 'registration', 'event' => 5, 'key' => '12A3', 'field' => 'registered_at', 'before' => null, 'after' => '2026-12-01 09:30:00.123456'],
            ],
            $this->decode($entries[$entriesBefore]['changes'])
        );

        $stale->status = RegistrationStatus::Registered;
        $stale->registeredAt = $this->utc('2026-12-01 09:31:00');
        try {
            $this->inRun(fn (array $input, Context $context) => $this->registrations->upsert($stale, $context));
            self::fail('The run should have conflicted.');
        } catch (VersionConflict) {
            $row = $this->rowOf('kalaka_registrations', 5, '12A3');
            self::assertSame('2', $row['version'] ?? null);
            self::assertSame('2026-12-01 09:30:00.123456', $row['registered_at'] ?? null);
        }
    }

    public function testARegistrationRowIsLockedAfterTheEventRowAndNotBefore(): void
    {
        $this->insertRegistration($this->newRegistration(5, '12A3'));
        $key = new RegistrationKey(5, '12A3');

        $found = $this->inRun(function (array $input, Context $context) use ($key): ?Registration {
            $context->lock(LockLevel::Event);

            return $this->registrations->findForUpdate($key, $context);
        });
        self::assertInstanceOf(Registration::class, $found);

        $this->expectException(SystemFailure::class);
        $this->inRun(function (array $input, Context $context) use ($key): void {
            $this->registrations->findForUpdate($key, $context);
            $context->lock(LockLevel::Event);
        });
    }

    /** @return list<array{RegistrationKey, RegistrationKey}> */
    public static function descendingPairs(): array
    {
        return [
            [new RegistrationKey(5, '2B'), new RegistrationKey(5, '1A')],
            [new RegistrationKey(6, '1A'), new RegistrationKey(5, '9Z')],
        ];
    }

    /** @dataProvider descendingPairs */
    public function testRegistrationsLockedInDescendingKeyOrderAreASystemFailure(RegistrationKey $first, RegistrationKey $second): void
    {
        $this->seedCodes($first->eventId, [$first->code]);
        $this->seedCodes($second->eventId, [$second->code]);

        $this->expectException(SystemFailure::class);
        $this->inRun(function (array $input, Context $context) use ($first, $second): void {
            $this->registrations->findForUpdate($first, $context);
            $this->registrations->findForUpdate($second, $context);
        });
    }

    public function testAscendingKeyOrderAndTheSameRowTwicePass(): void
    {
        $this->seedCodes(5, ['1A', '2B']);

        $this->inRun(function (array $input, Context $context): void {
            $this->registrations->findForUpdate(new RegistrationKey(5, '1A'), $context);
            $this->registrations->findForUpdate(new RegistrationKey(5, '2B'), $context);
            $this->registrations->findForUpdate(new RegistrationKey(5, '2B'), $context);
        });

        self::addToAssertionCount(1);
    }

    public function testInsertingALowerCodeThanAHeldOneIsNotKeyChecked(): void
    {
        $this->seedCodes(5, ['5E']);

        $this->inRun(function (array $input, Context $context): void {
            $this->registrations->findForUpdate(new RegistrationKey(5, '5E'), $context);
            $this->registrations->upsert($this->newRegistration(5, '1A'), $context);
        });

        self::assertSame(2, $this->registrationCount());
    }
}
