<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Error\RetryableFailure;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;

final class RunnerTest extends RunnerTestCase
{
    public function testRunReturnsTheResultAndCommitsTheRow(): void
    {
        $useCase = new AdminProbe(function (array $input, Context $context): string {
            $this->insertRow('a');

            return 'result';
        });

        self::assertSame('result', $this->runner->run($useCase, [], new SystemActor()));
        self::assertSame(['a'], $this->rows());
    }

    public function testKalakaExceptionIsThrownAsIsAndRollsBack(): void
    {
        $error = new RuleViolation();
        $useCase = new AdminProbe(function () use ($error): void {
            $this->insertRow('a');
            throw $error;
        });

        try {
            $this->runner->run($useCase, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (RuleViolation $caught) {
            self::assertSame($error, $caught);
        }
        self::assertSame([], $this->rows());
        self::assertSame(1, $useCase->calls);
    }

    public function testAnythingElseBecomesASystemFailureWithoutTheOriginalText(): void
    {
        $original = new \RuntimeException('secret detail');
        $useCase = new AdminProbe(function () use ($original): void {
            $this->insertRow('a');
            throw $original;
        });

        try {
            $this->runner->run($useCase, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (SystemFailure $caught) {
            self::assertSame(SystemFailure::class, $caught::class);
            self::assertSame($original, $caught->getPrevious());
            $wpError = $caught->toWpError();
            self::assertSame('kalaka_system_failure', $wpError->get_error_code());
            self::assertSame(['status' => 500], $wpError->get_error_data());
            self::assertSame('Váratlan hiba történt. Próbáld újra később.', $wpError->get_error_message());
            self::assertStringNotContainsString('secret detail', $caught->getMessage());
        }
        self::assertSame([], $this->rows());
    }

    public function testAPhpErrorIsWrappedToo(): void
    {
        $useCase = new AdminProbe(function (): void {
            $this->insertRow('a');
            intdiv(1, 0);
        });

        try {
            $this->runner->run($useCase, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (SystemFailure $caught) {
            self::assertInstanceOf(\DivisionByZeroError::class, $caught->getPrevious());
        }
        self::assertSame([], $this->rows());
    }

    public function testARetryableFailureIsRetriedFromScratch(): void
    {
        $useCase = new AdminProbe(function (array $input, Context $context, int $call): string {
            $this->insertRow('attempt ' . $call);
            if ($call < 3) {
                throw new RetryableFailure();
            }

            return 'done';
        });

        self::assertSame('done', $this->runner->run($useCase, [], new SystemActor()));
        self::assertSame(3, $useCase->calls);
        self::assertSame(['attempt 3'], $this->rows());
    }

    public function testRetriesStopAfterFourAttemptsAndTheLastErrorIsThrown(): void
    {
        $thrown = [];
        $useCase = new AdminProbe(function () use (&$thrown): void {
            $this->insertRow('a');
            $thrown[] = new RetryableFailure();
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test double; nothing is printed
            throw end($thrown);
        });

        try {
            $this->runner->run($useCase, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (RetryableFailure $caught) {
            self::assertCount(4, $thrown);
            self::assertSame($thrown[3], $caught);
        }
        self::assertSame(4, $useCase->calls);
        self::assertSame([], $this->rows());
    }

    public function testAVersionConflictIsRetriedForTheSystemActor(): void
    {
        $useCase = new AdminProbe(function (array $input, Context $context, int $call): string {
            if ($call < 2) {
                throw new VersionConflict();
            }

            return 'done';
        });
        self::assertSame('done', $this->runner->run($useCase, [], new SystemActor()));
        self::assertSame(2, $useCase->calls);

        $always = new AdminProbe(function (): void {
            throw new VersionConflict();
        });
        try {
            $this->runner->run($always, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (VersionConflict) {
            self::assertSame(4, $always->calls);
        }
    }

    public function testAVersionConflictIsNotRetriedForTheCli(): void
    {
        $useCase = new AdminProbe(function (): void {
            throw new VersionConflict();
        });

        $this->expectException(VersionConflict::class);
        try {
            $this->runner->run($useCase, [], new CliActor(null));
        } finally {
            self::assertSame(1, $useCase->calls);
        }
    }

    public function testTheClockIsReadOncePerAttemptAndSharedByNestedRuns(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-11-30 08:00:00', new \DateTimeZone('UTC')), true);
        $runner = new \Kalaka\Foundation\Api\UseCase\Runner($clock, true);
        $actor = new SystemActor();
        $seen = [];

        $nested = new AdminProbe(function (array $input, Context $context) use (&$seen): void {
            $seen[] = ['nested', $context->now()->format('H:i:s')];
        });
        $useCase = new AdminProbe(function (
            array $input,
            Context $context,
            int $call
        ) use (
            &$seen,
            $nested,
            $actor
        ): void {
            self::assertSame($actor, $context->actor());
            $seen[] = ['outer', $context->now()->format('H:i:s')];
            $context->run($nested, []);
            if ($call < 3) {
                throw new RetryableFailure();
            }
        });

        $runner->run($useCase, [], $actor);

        self::assertSame(3, $clock->calls);
        self::assertSame([
            ['outer', '08:00:00'], ['nested', '08:00:00'],
            ['outer', '08:00:01'], ['nested', '08:00:01'],
            ['outer', '08:00:02'], ['nested', '08:00:02'],
        ], $seen);
    }

    public function testTheContextHasTheFixedClockValueAndTheActor(): void
    {
        $actor = new CliActor(null);
        $useCase = new AdminProbe(function (array $input, Context $context) use ($actor): void {
            self::assertSame($actor, $context->actor());
            self::assertSame($this->clock->now(), $context->now());
        });

        $this->runner->run($useCase, [], $actor);
        self::assertSame(1, $useCase->calls);
    }

    public function testALockLevelBelowAHigherOneFails(): void
    {
        $inOrder = new AdminProbe(function (array $input, Context $context): void {
            $this->insertRow('ordered');
            $context->lock(LockLevel::Event);
            $context->lock(LockLevel::Quota);
            $context->lock(LockLevel::Registration);
            $context->lock(LockLevel::Registration);
            $context->lock(LockLevel::Other);
        });
        $this->runner->run($inOrder, [], new SystemActor());
        self::assertSame(['ordered'], $this->rows());

        $outOfOrder = new AdminProbe(function (array $input, Context $context): void {
            $this->insertRow('unordered');
            $context->lock(LockLevel::Registration);
            $context->lock(LockLevel::Event);
        });
        try {
            $this->runner->run($outOfOrder, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (SystemFailure $caught) {
            self::assertSame(SystemFailure::class, $caught::class);
        }
        self::assertSame(['ordered'], $this->rows());
    }

    public function testAUseCaseWithoutASideIsAProgrammingError(): void
    {
        $useCase = new UnsidedUseCase(function (): void {
            $this->insertRow('a');
        });

        try {
            $this->runner->run($useCase, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (SystemFailure $caught) {
            self::assertSame(SystemFailure::class, $caught::class);
        }
        self::assertSame(0, $useCase->calls);
        self::assertSame([], $this->rows());
    }

    public function testAUseCaseOnBothSidesIsAProgrammingError(): void
    {
        $useCase = new BothSidesUseCase(function (): void {
            $this->insertRow('a');
        });

        try {
            $this->runner->run($useCase, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (SystemFailure $caught) {
            self::assertSame(SystemFailure::class, $caught::class);
        }
        self::assertSame(0, $useCase->calls);
        self::assertSame([], $this->rows());
    }
}
