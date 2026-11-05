<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\SecretLinkActor;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RetryableFailure;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\Api\RegistrationKey;

require_once __DIR__ . '/RunnerTestCase.php';

final class RunnerNestingTest extends RunnerTestCase
{
    public function testNestedRunsAreCommittedTogether(): void
    {
        $nested = new AdminProbe(function (): string {
            $this->insertRow('nested');

            return 'inner';
        });
        $parent = new AdminProbe(function (array $input, Context $context) use ($nested): string {
            $this->insertRow('parent');

            return 'outer+' . $context->run($nested, []);
        });

        self::assertSame('outer+inner', $this->runner->run($parent, [], new SystemActor()));
        self::assertSame(['parent', 'nested'], $this->rows());
    }

    public function testAParentThrowingAfterTheNestedRunRollsBothBack(): void
    {
        $nested = new AdminProbe(function (): void {
            $this->insertRow('nested');
        });
        $parent = new AdminProbe(function (array $input, Context $context) use ($nested): void {
            $this->insertRow('parent');
            $context->run($nested, []);
            throw new RuleViolation();
        });

        $this->expectException(RuleViolation::class);
        try {
            $this->runner->run($parent, [], new SystemActor());
        } finally {
            self::assertSame([], $this->rows());
        }
    }

    public function testACaughtNestedErrorStillEndsTheWholeRun(): void
    {
        $error = new RuleViolation('first');
        $nested = new AdminProbe(function () use ($error): void {
            $this->insertRow('nested');
            throw $error;
        });
        $parent = new AdminProbe(function (array $input, Context $context) use ($nested): string {
            $this->insertRow('parent');
            try {
                $context->run($nested, []);
            } catch (RuleViolation) {
                // The parent carries on, as if nothing happened.
            }

            return 'returned normally';
        });

        try {
            $this->runner->run($parent, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (RuleViolation $caught) {
            self::assertSame($error, $caught);
        }
        self::assertSame([], $this->rows());
    }

    public function testAParentThatReplacesTheNestedErrorWithItsOwnStillThrowsTheNestedOne(): void
    {
        $nestedError = new RuleViolation('nested');
        $nested = new AdminProbe(function () use ($nestedError): void {
            throw $nestedError;
        });
        $parent = new AdminProbe(function (array $input, Context $context) use ($nested): void {
            $this->insertRow('parent');
            try {
                $context->run($nested, []);
            } catch (RuleViolation) {
                throw new NotFound();
            }
        });

        try {
            $this->runner->run($parent, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (RuleViolation $caught) {
            self::assertSame($nestedError, $caught);
        }
        self::assertSame([], $this->rows());
    }

    public function testARetryableNestedErrorRetriesTheWholeUseCaseEvenIfCaught(): void
    {
        $nested = new AdminProbe(function (array $input, Context $context, int $call): void {
            $this->insertRow('nested ' . $call);
            if ($call === 1) {
                throw new RetryableFailure();
            }
        });
        $parent = new AdminProbe(function (array $input, Context $context) use ($nested): void {
            try {
                $context->run($nested, []);
            } catch (RetryableFailure) {
                // Swallowed, but the run is already doomed.
            }
        });

        $this->runner->run($parent, [], new SystemActor());

        self::assertSame(2, $parent->calls);
        self::assertSame(['nested 2'], $this->rows());
    }

    public function testRunnerRunInsideARunningUseCaseFails(): void
    {
        $other = new AdminProbe(function (): void {
            $this->insertRow('inner');
        });
        $parent = new AdminProbe(function () use ($other): void {
            $this->insertRow('outer');
            $this->runner->run($other, [], new SystemActor());
        });

        try {
            $this->runner->run($parent, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (SystemFailure $caught) {
            self::assertSame(SystemFailure::class, $caught::class);
        }
        self::assertSame(0, $other->calls);
        self::assertSame([], $this->rows());
    }

    public function testRunnerRunInsideARunningUseCaseDoomsTheOuterRunEvenIfCaught(): void
    {
        $otherRunner = new Runner($this->clock, true);
        $other = new AdminProbe(function (): void {
        });
        $parent = new AdminProbe(function () use ($other, $otherRunner): string {
            $this->insertRow('outer');
            try {
                $otherRunner->run($other, [], new SystemActor());
            } catch (SystemFailure) {
                // Swallowed.
            }

            return 'returned normally';
        });

        $this->expectException(SystemFailure::class);
        try {
            $this->runner->run($parent, [], new SystemActor());
        } finally {
            self::assertSame([], $this->rows());
            self::assertSame(0, $other->calls);
        }
    }

    public function testRunnerIsUsableAgainAfterAFailedRun(): void
    {
        $failing = new AdminProbe(function (): void {
            throw new \RuntimeException('boom');
        });
        try {
            $this->runner->run($failing, [], new SystemActor());
        } catch (SystemFailure) {
            // Expected.
        }

        $useCase = new AdminProbe(function (): string {
            return 'fine';
        });
        self::assertSame('fine', $this->runner->run($useCase, [], new SystemActor()));
    }

    public function testAPortalRunnerMayNestAnAdminUseCase(): void
    {
        $key = new RegistrationKey(10, 'ABCD');
        $admin = new AdminProbe(function (): void {
            $this->insertRow('admin');
        });
        $portal = new PortalProbe([$key], function (array $input, Context $context) use ($admin): string {
            $this->insertRow('portal');
            $context->run($admin, []);

            return 'ok';
        });

        self::assertSame('ok', $this->runner->run($portal, [], new SecretLinkActor($key)));
        self::assertSame(['portal', 'admin'], $this->rows());
    }

    public function testAfterCommitStepsRunAfterTheCommitInRegistrationOrder(): void
    {
        $log = [];
        $nested = new AdminProbe(function (array $input, Context $context) use (&$log): void {
            $context->afterCommit(function () use (&$log): void {
                $log[] = 'nested';
            });
        });
        $useCase = new AdminProbe(function (array $input, Context $context) use (&$log, $nested): string {
            $this->insertRow('row');
            $context->afterCommit(function () use (&$log): void {
                $log[] = 'first ' . count($this->rows()) . ' row';
            });
            $context->run($nested, []);
            $context->afterCommit(function () use (&$log): void {
                $log[] = 'last';
            });
            self::assertSame([], $log, 'No step runs inside the run.');

            return 'result';
        });

        self::assertSame('result', $this->runner->run($useCase, [], new SystemActor()));
        self::assertSame(['first 1 row', 'nested', 'last'], $log);
    }

    public function testAfterCommitStepsAreDroppedOnRollbackAndForRetriedAttempts(): void
    {
        $log = [];
        $retried = new AdminProbe(function (array $input, Context $context, int $call) use (&$log): void {
            $context->afterCommit(function () use (&$log, $call): void {
                $log[] = 'attempt ' . $call;
            });
            if ($call < 3) {
                throw new RetryableFailure();
            }
        });
        $this->runner->run($retried, [], new SystemActor());
        self::assertSame(['attempt 3'], $log);

        $log = [];
        $failing = new AdminProbe(function (array $input, Context $context) use (&$log): void {
            $context->afterCommit(function () use (&$log): void {
                $log[] = 'never';
            });
            throw new RuleViolation();
        });
        try {
            $this->runner->run($failing, [], new SystemActor());
        } catch (RuleViolation) {
            // Expected.
        }
        self::assertSame([], $log);
    }

    public function testAFailingAfterCommitStepIsLoggedAndTheRestStillRun(): void
    {
        $log = [];
        $useCase = new AdminProbe(function (array $input, Context $context) use (&$log): string {
            $this->insertRow('row');
            $context->afterCommit(function (): void {
                throw new \RuntimeException('step trouble');
            });
            $context->afterCommit(function () use (&$log): void {
                $log[] = 'second';
            });

            return 'result';
        });

        $result = null;
        $errorLog = $this->capturedErrorLog(function () use ($useCase, &$result): void {
            $result = $this->runner->run($useCase, [], new SystemActor());
        });

        self::assertSame('result', $result);
        self::assertSame(['second'], $log);
        self::assertSame(['row'], $this->rows());
        self::assertStringContainsString(
            'Kaláka: after-commit step of kalaka/probe failed: RuntimeException: step trouble',
            $errorLog
        );
    }

    public function testAnAfterCommitStepMayRunAnotherUseCase(): void
    {
        $second = new AdminProbe(function (): string {
            $this->insertRow('second');

            return 'second result';
        });
        $secondResult = null;
        $first = new AdminProbe(function (array $input, Context $context) use ($second, &$secondResult): void {
            $this->insertRow('first');
            $context->afterCommit(function () use ($second, &$secondResult): void {
                $secondResult = $this->runner->run($second, [], new SystemActor());
            });
        });

        $this->runner->run($first, [], new SystemActor());

        self::assertSame('second result', $secondResult);
        self::assertSame(['first', 'second'], $this->rows());
    }

    public function testAContextKeptPastItsRunIsRefusedButStaysReadable(): void
    {
        $kept = null;
        $actor = new SystemActor();
        $useCase = new AdminProbe(function (array $input, Context $context) use (&$kept): void {
            $kept = $context;
        });
        $this->runner->run($useCase, [], $actor);

        self::assertSame($actor, $kept->actor());
        self::assertSame($this->clock->now(), $kept->now());
        foreach ([
            fn () => $kept->run($useCase, []),
            fn () => $kept->afterCommit(function (): void {
            }),
            fn () => $kept->lock(LockLevel::Event),
        ] as $call) {
            try {
                $call();
                self::fail('The call should have thrown.');
            } catch (SystemFailure $caught) {
                self::assertSame(SystemFailure::class, $caught::class);
            }
        }
    }

    public function testAnAfterCommitStepUsingTheEndedContextFailsAndIsLogged(): void
    {
        $outcomes = [];
        $nested = new AdminProbe(function (): void {
        });
        $useCase = new AdminProbe(function (array $input, Context $context) use (&$outcomes, $nested): void {
            $context->afterCommit(function () use (&$outcomes, $context, $nested): void {
                $calls = [
                    'run' => fn () => $context->run($nested, []),
                    'afterCommit' => fn () => $context->afterCommit(function (): void {
                    }),
                    'lock' => fn () => $context->lock(LockLevel::Event),
                ];
                foreach ($calls as $name => $call) {
                    try {
                        $call();
                    } catch (SystemFailure $error) {
                        $outcomes[] = $name;
                    }
                }
                throw new \RuntimeException('step done');
            });
        });

        $errorLog = $this->capturedErrorLog(function () use ($useCase): void {
            $this->runner->run($useCase, [], new SystemActor());
        });

        self::assertSame(['run', 'afterCommit', 'lock'], $outcomes);
        self::assertSame(0, $nested->calls);
        self::assertStringContainsString('after-commit step of kalaka/probe failed', $errorLog);
    }

    public function testNestedUseCasesSeeTheOuterLockLevels(): void
    {
        $nested = new AdminProbe(function (array $input, Context $context): void {
            $context->lock(LockLevel::Event);
        });
        $parent = new AdminProbe(function (array $input, Context $context) use ($nested): void {
            $context->lock(LockLevel::Registration);
            $context->run($nested, []);
        });

        $this->expectException(SystemFailure::class);
        $this->runner->run($parent, [], new SystemActor());
    }
}
