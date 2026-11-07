<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\SecretLinkActor;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Audit\Correlation;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Database\DatabaseError;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\RetryableFailure;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;

/** Failed entries: written after the rollback, in a transaction of their own. */
final class AuditFailureTest extends AuditTestCase
{
    private function registration(string $code, int $event = 12): Subject
    {
        return Subject::registration(new RegistrationKey($event, $code));
    }

    /** @return array<string, mixed> */
    private function onlyFailedError(): array
    {
        $entry = $this->onlyEntry();
        self::assertSame('failed', $entry['outcome']);
        self::assertNull($entry['changes']);
        self::assertNull($entry['reason']);

        return $this->decode($entry['error']);
    }

    public function testAnUnexpectedExceptionIsAuditedAsAFailureAndNeverAsASuccess(): void
    {
        $thrownAt = 0;
        $useCase = new AdminProbe(function (array $input, Context $context) use (&$thrownAt): void {
            $context->touch($this->registration('00B1'));
            $this->insertRow('a');
            $thrownAt = __LINE__ + 1;
            throw new \RuntimeException('Teszt Elek részlete');
        }, event: 12, targets: [new RegistrationKey(12, '00A0')]);

        try {
            $this->runner->run($useCase, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (SystemFailure) {
            self::assertSame([], $this->rows());
        }

        $entry = $this->onlyEntry();
        $error = $this->decode($entry['error']);
        self::assertSame('failed', $entry['outcome']);
        self::assertSame(['event/12/12', 'registration/12/00A0'], $this->subjectsOf($entry['id']));
        self::assertSame('kalaka/probe', $error['use_case']);
        self::assertSame(SystemFailure::class, $error['class']);
        self::assertSame('kalaka_system_failure', $error['code']);
        self::assertSame(500, $error['status']);
        self::assertSame('Váratlan hiba történt. Próbáld újra később.', $error['message']);
        self::assertSame('kalaka/src/Foundation/Api/UseCase/Runner.php', $error['file']);
        self::assertCount(1, $error['previous']);
        self::assertSame(\RuntimeException::class, $error['previous'][0]['class']);
        self::assertSame(0, $error['previous'][0]['code']);
        self::assertSame('kalaka/tests/integration/Foundation/AuditFailureTest.php', $error['previous'][0]['file']);
        self::assertSame($thrownAt, $error['previous'][0]['line']);
        self::assertStringNotContainsString('részlete', (string) $entry['error']);
    }

    public function testADatabaseErrorNamesTheFailingStatementNotDatabaseError(): void
    {
        $checkedAt = 0;
        $useCase = new AdminProbe(function () use (&$checkedAt): void {
            global $wpdb;
            $suppressed = $wpdb->suppress_errors(true);
            try {
                $wpdb->query('SELECT 1 FROM kalaka_no_such_table');
                $checkedAt = __LINE__ + 1;
                DatabaseError::check($wpdb);
            } finally {
                $wpdb->suppress_errors($suppressed);
            }
        });

        try {
            $this->runner->run($useCase, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (SystemFailure) {
        }

        $entry = $this->onlyEntry();
        $error = $this->decode($entry['error']);
        $file = 'kalaka/tests/integration/Foundation/AuditFailureTest.php';
        self::assertSame($file, $error['file']);
        self::assertSame($checkedAt, $error['line']);
        self::assertSame(
            [['class' => \RuntimeException::class, 'code' => 1146, 'file' => $file, 'line' => $checkedAt]],
            $error['previous']
        );
        self::assertStringNotContainsString('kalaka_no_such_table', (string) $entry['error']);
    }

    public function testPersonalValuesNeverReachAFailedEntryOrTheErrorLog(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'name' => ['type' => 'string'], 'email' => ['type' => 'string'], 'reason' => ['type' => 'string'],
        ]];
        $thrownAt = 0;
        $useCase = new AdminProbe(function (array $input, Context $context) use (&$thrownAt): void {
            $context->change($this->registration('00A0'), 'name', 'Teszt Elek', $input['name'] . ' Béla');
            $context->change($this->registration('00A0'), 'email', null, $input['email']);
            $thrownAt = __LINE__ + 1;
            throw new RuleViolation();
        }, $schema, 12, [new RegistrationKey(12, '00A0')]);
        $input = ['name' => 'Teszt Elek', 'email' => 'teszt.elek@example.com', 'reason' => 'Teszt Elek kérte'];

        $log = $this->capturedErrorLog(function () use ($useCase, $input): void {
            try {
                $this->runner->run($useCase, $input, new SystemActor());
                self::fail('The run should have thrown.');
            } catch (RuleViolation) {
            }
        });

        $entry = $this->onlyEntry();
        $error = $this->decode($entry['error']);
        self::assertNull($entry['changes']);
        self::assertNull($entry['reason']);
        self::assertSame('kalaka/tests/integration/Foundation/AuditFailureTest.php', $error['file']);
        self::assertSame($thrownAt, $error['line']);

        global $wpdb;
        $everything = $log . "\n" . implode("\n", array_map('strval', $entry))
            . "\n" . implode("\n", $this->subjectsOf($entry['id']))
            . "\n" . implode("\n", array_map('strval', (array) $wpdb->get_col(
                "SELECT record_key FROM {$wpdb->prefix}kalaka_audit_subjects"
            )));
        foreach (['Teszt Elek', 'teszt.elek@example.com', 'Teszt Elek kérte', 'kérte'] as $personal) {
            self::assertStringNotContainsString($personal, $everything);
        }
    }

    public function testARefusedUserIsAuditedWithTheDeclaredSubjects(): void
    {
        $useCase = new AdminProbe(static function (): void {
        }, event: 12, targets: [new RegistrationKey(12, '00A0')]);

        try {
            $this->runner->run($useCase, [], new UserActor(self::factory()->user->create()));
            self::fail('The run should have thrown.');
        } catch (PermissionDenied) {
        }

        $entry = $this->onlyEntry();
        self::assertSame('kalaka_permission_denied', $this->decode($entry['error'])['code']);
        self::assertSame('user', $entry['actor_type']);
        self::assertSame(['event/12/12', 'registration/12/00A0'], $this->subjectsOf($entry['id']));
    }

    public function testASecretLinkNamingAnotherRegistrationIsAudited(): void
    {
        $useCase = new PortalProbe([new RegistrationKey(12, '10A1')], static function (): void {
        });

        try {
            $this->runner->run($useCase, [], new SecretLinkActor(new RegistrationKey(12, '00A0')));
            self::fail('The run should have thrown.');
        } catch (PermissionDenied) {
        }

        $entry = $this->onlyEntry();
        self::assertSame('portal', $entry['side']);
        self::assertSame('secret_link', $entry['actor_type']);
        self::assertSame('12', $entry['actor_event_id']);
        self::assertSame('00A0', $entry['actor_code']);
        self::assertSame(['registration/12/10A1'], $this->subjectsOf($entry['id']));
        self::assertSame('kalaka_permission_denied', $this->decode($entry['error'])['code']);
    }

    public function testAUseCaseOnNeitherSideIsAuditedWithoutSubjects(): void
    {
        try {
            $this->runner->run(new UnsidedUseCase(static function (): void {
            }), [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (SystemFailure) {
        }

        $entry = $this->onlyEntry();
        self::assertSame('kalaka/unsided', $entry['action']);
        self::assertSame('admin', $entry['side']);
        self::assertSame([], $this->subjectsOf($entry['id']));
        self::assertSame('kalaka_system_failure', $this->decode($entry['error'])['code']);
    }

    public function testInputThatFailsItsSchemaWritesNothing(): void
    {
        $useCase = new AdminProbe(static function (): void {
        }, ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]]);

        try {
            $this->runner->run($useCase, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (InvalidInput) {
        }

        self::assertSame([], $this->entries());
    }

    public function testANestedRunWhoseInputFailsItsSchemaIsAFailureOfTheWholeRun(): void
    {
        $nested = new NamedProbe(static function (): void {
        }, ['type' => 'object', 'required' => ['name']]);
        $useCase = new AdminProbe(function (array $input, Context $context) use ($nested): void {
            $this->insertRow('a');
            $context->run($nested, []);
        });

        try {
            $this->runner->run($useCase, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (SystemFailure) {
            self::assertSame([], $this->rows());
        }

        $entry = $this->onlyEntry();
        self::assertSame('kalaka/probe', $entry['action']);
        $error = $this->decode($entry['error']);
        self::assertSame('kalaka_system_failure', $error['code']);
        self::assertSame('kalaka/named-probe', $error['use_case']);
    }

    public function testARetryExhaustedFailureIsOneFailedEntry(): void
    {
        $useCase = new AdminProbe(function (): void {
            $this->insertRow('a');
            throw new RetryableFailure();
        });

        try {
            $this->runner->run($useCase, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (RetryableFailure) {
        }

        self::assertSame(4, $useCase->calls);
        self::assertSame('kalaka_retryable_failure', $this->onlyFailedError()['code']);
    }

    public function testANestedFailureTheParentCatchesIsStillTheWholeRunsFailure(): void
    {
        $nested = new NamedProbe(static function (): void {
            throw new RuleViolation('Beágyazott szabály.');
        });
        $useCase = new AdminProbe(function (array $input, Context $context) use ($nested): string {
            $this->insertRow('a');
            try {
                $context->run($nested, []);
            } catch (RuleViolation) {
            }

            return 'carried on';
        });

        try {
            $this->runner->run($useCase, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (RuleViolation) {
            self::assertSame([], $this->rows());
        }

        $entry = $this->onlyEntry();
        self::assertSame('kalaka/probe', $entry['action']);
        self::assertSame('failed', $entry['outcome']);
        $error = $this->decode($entry['error']);
        self::assertSame('kalaka/named-probe', $error['use_case']);
        self::assertSame(RuleViolation::class, $error['class']);
        self::assertSame('A művelet nem hajtható végre. Beágyazott szabály.', $error['message']);
    }

    /** Makes every INSERT into an audit table invalid SQL; nothing else is touched. */
    private function breakAuditInserts(): void
    {
        add_filter('query', static function (string $query): string {
            return preg_match('/^\s*INSERT\s+INTO\s+\S*kalaka_audit_(entries|subjects)\b/i', $query) === 1
                ? 'INSERT INTO kalaka_audit_broken (' : $query;
        });
    }

    /** @return list<string> */
    private function auditLines(string $log): array
    {
        return array_values(array_filter(
            explode("\n", $log),
            static fn (string $line): bool => str_contains($line, 'audit entry not written')
        ));
    }

    public function testWhenTheFailedEntryCannotBeWrittenTheErrorLogGetsOneLineAndTheOriginalErrorIsThrown(): void
    {
        $original = new RuleViolation('Eredeti.');
        $useCase = new AdminProbe(function () use ($original): void {
            $this->insertRow('a');
            throw $original;
        });
        $this->breakAuditInserts();

        $caught = null;
        $log = $this->capturedErrorLog(function () use ($useCase, &$caught): void {
            try {
                $this->runner->run($useCase, [], new SystemActor());
            } catch (\Throwable $e) {
                $caught = $e;
            }
        });

        self::assertSame($original, $caught);
        self::assertSame([], $this->rows());
        $lines = $this->auditLines($log);
        self::assertCount(1, $lines);
        self::assertStringContainsString(
            'Kaláka: audit entry not written: kalaka/probe failed with kalaka_rule_violation, correlation '
            . Correlation::id() . ': ' . SystemFailure::class . ' <- RuntimeException (1064)',
            $lines[0]
        );
        self::assertCount(1, array_filter(explode("\n", trim($log))), 'Nothing else is logged.');
    }

    public function testWhenTheSucceededEntryCannotBeWrittenTheUseCaseIsRolledBack(): void
    {
        $useCase = new AdminProbe(function (): string {
            $this->insertRow('a');

            return 'done';
        });
        $this->breakAuditInserts();

        $caught = null;
        $log = $this->capturedErrorLog(function () use ($useCase, &$caught): void {
            try {
                $this->runner->run($useCase, [], new SystemActor());
            } catch (\Throwable $e) {
                $caught = $e;
            }
        });

        self::assertInstanceOf(SystemFailure::class, $caught);
        self::assertSame(SystemFailure::class, $caught::class);
        self::assertSame([], $this->rows());
        self::assertCount(1, $this->auditLines($log));
        self::assertStringContainsString('failed with kalaka_system_failure', $log);
    }
}
