<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\SecretLinkActor;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Audit\Correlation;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\Api\RegistrationKey;

require_once __DIR__ . '/AuditTestCase.php';

/** Succeeded entries: what the runner writes before the commit. */
final class AuditEntryTest extends AuditTestCase
{
    private function registration(string $code, int $event = 12): Subject
    {
        return Subject::registration(new RegistrationKey($event, $code));
    }

    public function testASucceededEntryHasEveryColumn(): void
    {
        update_option('timezone_string', 'Europe/Budapest');
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        $_SERVER['HTTP_USER_AGENT'] = 'Teszt-Bongeszo/1.0';
        $user = $this->fullAccessUser();
        $runner = new Runner($this->clockAt('2026-11-30 10:00:00'), true);

        $useCase = new AdminProbe(function (array $input, Context $context): void {
            $context->touch($this->registration('00B1'));
            $context->change($this->registration('00A0'), 'tier', 'A', 'B');
        }, event: 12, targets: [new RegistrationKey(12, '00A0')]);
        $runner->run($useCase, [], new UserActor($user));

        $entry = $this->onlyEntry();
        self::assertNull($entry['parent_id']);
        self::assertSame(Correlation::id(), $entry['correlation_id']);
        self::assertSame('2026-11-30 09:00:00.000000', $entry['occurred_at']);
        self::assertSame('admin', $entry['side']);
        self::assertSame('user', $entry['actor_type']);
        self::assertSame((string) $user, $entry['actor_user_id']);
        self::assertNull($entry['actor_event_id']);
        self::assertNull($entry['actor_code']);
        self::assertSame('kalaka/probe', $entry['action']);
        self::assertSame('192.0.2.10', $entry['ip_address']);
        self::assertSame('Teszt-Bongeszo/1.0', $entry['user_agent']);
        self::assertNull($entry['reason']);
        self::assertSame('succeeded', $entry['outcome']);
        self::assertNull($entry['error']);
        self::assertSame(
            [['type' => 'registration', 'event' => 12, 'key' => '00A0', 'field' => 'tier', 'before' => 'A', 'after' => 'B']],
            $this->decode($entry['changes'])
        );
        self::assertSame(['event/12/12', 'registration/12/00A0', 'registration/12/00B1'], $this->subjectsOf($entry['id']));
    }

    public function testAnEntryWithoutChangesHasAnEmptyList(): void
    {
        $this->runner->run(new AdminProbe(static function (): void {
        }), [], new SystemActor());

        self::assertSame('[]', $this->onlyEntry()['changes']);
    }

    public function testTheReasonIsStoredTrimmedAndSeenByExecute(): void
    {
        global $wpdb;
        $seen = null;
        $schema = ['type' => 'object', 'properties' => ['reason' => ['type' => 'string']]];
        $useCase = new AdminProbe(function (array $input, Context $context) use (&$seen): void {
            $seen = $input['reason'] ?? null;
            $context->change($this->registration('00A0'), 'tier', 'A', 'B');
        }, $schema);

        $this->runner->run($useCase, ['reason' => ' Kérésre átsorolva '], new SystemActor());

        $entry = $this->onlyEntry();
        self::assertSame('Kérésre átsorolva', $entry['reason']);
        self::assertNotNull($seen);
        self::assertSame('tier', $this->decode($entry['changes'])[0]['field']);
        self::assertSame($entry['id'], (string) $wpdb->get_var(
            "SELECT MAX(entry_id) FROM {$wpdb->prefix}kalaka_audit_subjects"
            . " WHERE record_type = 'registration' AND event_id = 12 AND record_key = '00A0'"
        ));
    }

    public function testAReasonTheSchemaDoesNotDeclareIsIgnored(): void
    {
        $this->runner->run(new AdminProbe(static function (): void {
        }), ['reason' => 'Nem deklarált'], new SystemActor());
        self::assertNull($this->onlyEntry()['reason']);
    }

    public function testAnEmptyReasonIsNull(): void
    {
        $schema = ['type' => 'object', 'properties' => ['reason' => ['type' => 'string']]];
        $this->runner->run(new AdminProbe(static function (): void {
        }, $schema), ['reason' => "  \n"], new SystemActor());
        self::assertNull($this->onlyEntry()['reason']);
    }

    public function testNestedEntriesAreLinkedAndKeepTheirOwnSubjectsChangesAndReason(): void
    {
        $schema = ['type' => 'object', 'properties' => ['reason' => ['type' => 'string']]];
        $nested = new NamedProbe(function (array $input, Context $context): void {
            $context->change($this->registration('00B1'), 'tier', 'C', 'D');
        }, $schema);
        $outer = new AdminProbe(function (array $input, Context $context) use ($nested): void {
            $context->touch($this->registration('00A0'));
            $context->run($nested, ['reason' => 'Beágyazott ok']);
        }, $schema);

        $this->runner->run($outer, ['reason' => 'Külső ok'], new SystemActor());

        $entries = $this->entries();
        self::assertCount(2, $entries);
        [$first, $second] = $entries;
        self::assertSame('kalaka/probe', $first['action']);
        self::assertSame('kalaka/named-probe', $second['action']);
        self::assertNull($first['parent_id']);
        self::assertSame($first['id'], $second['parent_id']);
        self::assertSame($first['correlation_id'], $second['correlation_id']);
        self::assertSame(['registration/12/00A0'], $this->subjectsOf($first['id']));
        self::assertSame(['registration/12/00B1'], $this->subjectsOf($second['id']));
        self::assertSame('[]', $first['changes']);
        self::assertSame('00B1', $this->decode($second['changes'])[0]['key']);
        self::assertSame('Külső ok', $first['reason']);
        self::assertSame('Beágyazott ok', $second['reason']);
    }

    public function testASecretIsMasked(): void
    {
        $useCase = new AdminProbe(function (array $input, Context $context): void {
            $context->change($this->registration('00A0'), 'link_token', null, 'tok-1234567890', true);
        });
        $this->runner->run($useCase, [], new SystemActor());

        $entry = $this->onlyEntry();
        self::assertSame(
            [['type' => 'registration', 'event' => 12, 'key' => '00A0', 'field' => 'link_token', 'masked' => true]],
            $this->decode($entry['changes'])
        );
        self::assertStringNotContainsString('tok-1234567890', implode("\n", array_map('strval', $entry)));
    }

    public function testChangesAreMerged(): void
    {
        $a = $this->registration('00A0');
        $useCase = new AdminProbe(function (array $input, Context $context) use ($a): void {
            $context->change($a, 'tier', 'A', 'B');
            $context->change($a, 'note', 'x', 'y');
            $context->change($a, 'tier', 'B', 'C');
            $context->change($a, 'name', 'N', 'M');
            $context->change($a, 'name', 'M', 'N');
            $context->change($this->registration('00B1'), 'tier', 'A', 'A');
        });
        $this->runner->run($useCase, [], new SystemActor());

        $entry = $this->onlyEntry();
        self::assertSame(
            [
                ['type' => 'registration', 'event' => 12, 'key' => '00A0', 'field' => 'tier', 'before' => 'A', 'after' => 'C'],
                ['type' => 'registration', 'event' => 12, 'key' => '00A0', 'field' => 'note', 'before' => 'x', 'after' => 'y'],
            ],
            $this->decode($entry['changes'])
        );
        self::assertSame(['registration/12/00A0', 'registration/12/00B1'], $this->subjectsOf($entry['id']));
    }

    public function testAFieldRecordedOnceAsSecretStaysMasked(): void
    {
        $a = $this->registration('00A0');
        $useCase = new AdminProbe(static function (array $input, Context $context) use ($a): void {
            $context->change($a, 'token', 'tok-old-1', 'tok-new-2', true);
            $context->change($a, 'token', 'tok-new-2', 'tok-newer-3');
        });
        $this->runner->run($useCase, [], new SystemActor());

        $entry = $this->onlyEntry();
        self::assertSame(true, $this->decode($entry['changes'])[0]['masked']);
        self::assertStringNotContainsString('tok-', (string) $entry['changes']);
    }

    public function testAValueThatIsNotJsonEncodableEndsTheRunWithASystemFailure(): void
    {
        $useCase = new AdminProbe(function (array $input, Context $context): void {
            $this->insertRow('a');
            $context->change($this->registration('00A0'), 'ratio', 1.0, NAN);
        });

        try {
            $this->runner->run($useCase, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (SystemFailure) {
            self::assertSame([], $this->rows());
        }
    }

    /** @return iterable<string, array{Actor, bool, bool, ?string, ?string}> actor, portal, with user, ip, user agent */
    public static function actors(): iterable
    {
        yield 'cli without a user' => [new CliActor(null), false, false, null, null];
        yield 'system' => [new SystemActor(), false, false, null, null];
        yield 'cli with a user' => [new CliActor(0), false, true, null, null];
        yield 'user' => [new UserActor(0), false, true, '192.0.2.10', 'Teszt-Bongeszo/1.0'];
        yield 'account' => [new AccountActor(0), true, true, '192.0.2.10', 'Teszt-Bongeszo/1.0'];
        yield 'secret link' => [new SecretLinkActor(new RegistrationKey(12, '00A0')), true, false, '192.0.2.10', 'Teszt-Bongeszo/1.0'];
    }

    /** @dataProvider actors */
    public function testActorColumnsIpAndUserAgent(Actor $actor, bool $portal, bool $withUser, ?string $ip, ?string $agent): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        $_SERVER['HTTP_USER_AGENT'] = 'Teszt-Bongeszo/1.0';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
        $user = $this->fullAccessUser();
        if ($actor instanceof CliActor && $withUser) {
            $actor = new CliActor($user);
        } elseif ($actor instanceof UserActor) {
            $actor = new UserActor($user);
        } elseif ($actor instanceof AccountActor) {
            $actor = new AccountActor($user);
        }

        $noop = static function (): void {
        };
        $useCase = $portal
            ? new PortalProbe($actor instanceof SecretLinkActor ? [$actor->registration] : [], $noop)
            : new AdminProbe($noop);
        $this->runner->run($useCase, [], $actor);

        $entry = $this->onlyEntry();
        self::assertSame($portal ? 'portal' : 'admin', $entry['side']);
        self::assertSame($actor->type(), $entry['actor_type']);
        self::assertSame($withUser ? (string) $user : null, $entry['actor_user_id']);
        self::assertSame($actor instanceof SecretLinkActor ? '12' : null, $entry['actor_event_id']);
        self::assertSame($actor instanceof SecretLinkActor ? '00A0' : null, $entry['actor_code']);
        self::assertSame($ip, $entry['ip_address']);
        self::assertSame($agent, $entry['user_agent']);
    }

    public function testAnInvalidAddressAndALongUserAgent(): void
    {
        $user = $this->fullAccessUser();
        $noop = static function (): void {
        };

        $_SERVER['REMOTE_ADDR'] = 'not-an-ip';
        $_SERVER['HTTP_USER_AGENT'] = str_repeat('ő', 300);
        $this->runner->run(new AdminProbe($noop), [], new UserActor($user));

        $entry = $this->onlyEntry();
        self::assertNull($entry['ip_address']);
        self::assertSame(str_repeat('ő', 255), $entry['user_agent']);
    }

    public function testAnIpv6AddressIsKept(): void
    {
        $_SERVER['REMOTE_ADDR'] = '2001:db8::1';
        unset($_SERVER['HTTP_USER_AGENT']);
        $this->runner->run(new AdminProbe(static function (): void {
        }), [], new UserActor($this->fullAccessUser()));

        $entry = $this->onlyEntry();
        self::assertSame('2001:db8::1', $entry['ip_address']);
        self::assertNull($entry['user_agent']);
    }

    public function testASubjectNeedsAValidTypeKeyAndEvent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Subject('Registration', 12, '00A0');
    }

    public function testASubjectMadeInsideARunEndsItWithASystemFailure(): void
    {
        $useCase = new AdminProbe(function (): void {
            $this->insertRow('a');
            Subject::registration(new RegistrationKey(12, ''));
        });

        try {
            $this->runner->run($useCase, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (SystemFailure) {
            self::assertSame([], $this->rows());
        }
        self::assertSame('failed', $this->onlyEntry()['outcome']);
    }

    public function testRecordingOnAnEndedContextIsRefused(): void
    {
        $kept = null;
        $this->runner->run(new AdminProbe(function (array $input, Context $context) use (&$kept): void {
            $kept = $context;
        }), [], new SystemActor());
        self::assertInstanceOf(Context::class, $kept);

        foreach ([
            fn () => $kept->touch($this->registration('00A0')),
            fn () => $kept->change($this->registration('00A0'), 'tier', 'A', 'B'),
        ] as $record) {
            try {
                $record();
                self::fail('Recording should have been refused.');
            } catch (SystemFailure) {
                self::assertCount(1, $this->entries());
            }
        }
    }

    public function testEveryEntryOfARequestCarriesTheSameCorrelationId(): void
    {
        $nested = new NamedProbe(static function (): void {
        });
        $afterCommit = new NamedProbe(static function (): void {
        });
        $first = new AdminProbe(function (array $input, Context $context) use ($nested, $afterCommit): void {
            $context->run($nested, []);
            $context->afterCommit(fn () => $this->runner->run($afterCommit, [], new SystemActor()));
        });

        $this->runner->run($first, [], new SystemActor());
        $this->runner->run(new AdminProbe(static function (): void {
        }), [], new SystemActor());

        $entries = $this->entries();
        self::assertCount(4, $entries);
        foreach ($entries as $entry) {
            self::assertSame(Correlation::id(), $entry['correlation_id']);
        }
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            Correlation::id()
        );
    }

    public function testASuccessfulRetryWritesOneEntryWithTheLastAttemptsSubjects(): void
    {
        $useCase = new AdminProbe(function (array $input, Context $context, int $call): void {
            $context->touch($this->registration('A00' . $call));
            if ($call < 3) {
                throw new \Kalaka\Foundation\Api\Error\RetryableFailure();
            }
        });

        $this->runner->run($useCase, [], new SystemActor());

        $entry = $this->onlyEntry();
        self::assertSame('succeeded', $entry['outcome']);
        self::assertSame(['registration/12/A003'], $this->subjectsOf($entry['id']));
    }
}
