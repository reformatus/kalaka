<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;

/** `Registrations\Api\Registrations::lock()`: the registration row `FOR UPDATE`, held by the run. */
final class RegistrationsLockTest extends RegistrationsTestCase
{
    public function testAStoredRegistrationIsLockedAndHeld(): void
    {
        $key = new RegistrationKey(5, '12A3');
        $this->insertRegistration($this->newRegistration(5, '12A3'));
        $statements = [];
        $capture = static function (string $query) use (&$statements): string {
            $statements[] = $query;

            return $query;
        };

        $seen = $this->inRun(function (array $input, Context $context) use ($key, $capture): array {
            add_filter('query', $capture);
            try {
                $locked = (new Registrations())->lock($key, $context);
            } finally {
                remove_filter('query', $capture);
            }

            return [$locked, $context->holdsRegistration($key)];
        });

        self::assertSame([true, true], $seen);
        self::assertCount(1, $statements);
        self::assertStringContainsString('kalaka_registrations', $statements[0]);
        self::assertStringEndsWith('FOR UPDATE', $statements[0]);
    }

    public function testAKeyWithoutARowIsNotLockedAndNotHeld(): void
    {
        $key = new RegistrationKey(5, '12A3');

        $seen = $this->inRun(
            fn (array $input, Context $context): array => [
                (new Registrations())->lock($key, $context),
                $context->holdsRegistration($key),
            ]
        );

        self::assertSame([false, false], $seen);
    }

    public function testALockAfterAHigherLevelIsALockOrderFailure(): void
    {
        $key = new RegistrationKey(5, '12A3');
        $this->insertRegistration($this->newRegistration(5, '12A3'));

        $this->expectException(SystemFailure::class);
        $this->inRun(function (array $input, Context $context) use ($key): void {
            $context->lock(LockLevel::Other);
            (new Registrations())->lock($key, $context);
        });
    }
}
