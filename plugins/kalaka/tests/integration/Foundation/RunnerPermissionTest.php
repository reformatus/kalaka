<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\SecretLinkActor;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Audit\Correlation;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\UseCase\UseCase;
use Kalaka\Registrations\Api\RegistrationKey;

final class RunnerPermissionTest extends RunnerTestCase
{
    /** @return iterable<string, array{Actor, string, bool}> actor, use case, passes */
    public static function cases(): iterable
    {
        $own = new RegistrationKey(10, '12A3');

        yield 'system, admin' => [new SystemActor(), 'admin', true];
        yield 'system, site-level admin' => [new SystemActor(), 'site', true];
        yield 'cli without user, admin' => [new CliActor(null), 'admin', true];
        yield 'cli without user, site-level admin' => [new CliActor(null), 'site', true];

        yield 'secret link, its own registration' => [new SecretLinkActor($own), 'own', true];
        yield 'secret link, its own twice' => [new SecretLinkActor($own), 'own twice', true];
        yield 'secret link, another code' => [new SecretLinkActor($own), 'other code', false];
        yield 'secret link, another event' => [new SecretLinkActor($own), 'other event', false];
        yield 'secret link, its own and another' => [new SecretLinkActor($own), 'own and other', false];
        yield 'secret link, none' => [new SecretLinkActor($own), 'none', false];
        yield 'secret link, admin' => [new SecretLinkActor($own), 'admin', false];

        yield 'user, portal' => [new UserActor(5), 'own', false];
        yield 'system, portal' => [new SystemActor(), 'none', false];
        yield 'cli, portal' => [new CliActor(null), 'none', false];
        yield 'account, a registration' => [new AccountActor(5), 'own', false];
        yield 'account, account-level' => [new AccountActor(5), 'none', true];
        yield 'account, admin' => [new AccountActor(5), 'admin', false];
    }

    /** @dataProvider cases */
    public function testCoarseCheck(Actor $actor, string $kind, bool $passes): void
    {
        $body = function (): string {
            $this->insertRow('a');

            return 'ran';
        };
        $own = new RegistrationKey(10, '12A3');
        $useCase = match ($kind) {
            'admin' => new AdminProbe($body, event: 3),
            'site' => new AdminProbe($body),
            'own' => new PortalProbe([$own], $body),
            'own twice' => new PortalProbe([$own, new RegistrationKey(10, '12A3')], $body),
            'other code' => new PortalProbe([new RegistrationKey(10, '00B0')], $body),
            'other event' => new PortalProbe([new RegistrationKey(11, '12A3')], $body),
            'own and other' => new PortalProbe([$own, new RegistrationKey(10, '00B0')], $body),
            'none' => new PortalProbe([], $body),
        };

        if ($passes) {
            self::assertSame('ran', $this->runner->run($useCase, [], $actor));
            self::assertSame(['a'], $this->rows());

            return;
        }

        try {
            $this->runner->run($useCase, [], $actor);
            self::fail('The run should have thrown.');
        } catch (PermissionDenied $caught) {
            $wpError = $caught->toWpError();
            self::assertSame('kalaka_permission_denied', $wpError->get_error_code());
            self::assertSame(['status' => 403, 'correlation' => Correlation::id()], $wpError->get_error_data());
            self::assertSame('Ehhez nincs jogosultságod.', $wpError->get_error_message());
        }
        self::assertSame(0, self::calls($useCase));
        self::assertSame([], $this->rows());
    }

    /** @return iterable<string, array{string, bool, ?int}> actor type, has the capability, event */
    public static function userCases(): iterable
    {
        foreach (['user' => 'a user', 'cli' => 'a CLI user'] as $type => $label) {
            foreach (['event-level' => 3, 'site-level' => null] as $level => $event) {
                yield "$label with the capability, $level" => [$type, true, $event];
                yield "$label without the capability, $level" => [$type, false, $event];
            }
        }
    }

    /** @dataProvider userCases */
    public function testFullAccessCapabilityDecidesForUsers(string $type, bool $hasCapability, ?int $event): void
    {
        $userId = self::factory()->user->create(['role' => 'administrator']);
        if ($hasCapability) {
            get_userdata($userId)->add_cap(AdminAccess::FULL_ACCESS);
        }
        $actor = $type === 'user' ? new UserActor($userId) : new CliActor($userId);
        $useCase = new AdminProbe(function (): string {
            $this->insertRow('a');

            return 'ran';
        }, event: $event);

        if ($hasCapability) {
            self::assertSame('ran', $this->runner->run($useCase, [], $actor));
            self::assertSame(['a'], $this->rows());

            return;
        }

        try {
            $this->runner->run($useCase, [], $actor);
            self::fail('The run should have thrown.');
        } catch (PermissionDenied $caught) {
            $wpError = $caught->toWpError();
            self::assertSame('kalaka_permission_denied', $wpError->get_error_code());
            self::assertSame(['status' => 403, 'correlation' => Correlation::id()], $wpError->get_error_data());
            self::assertSame('Ehhez nincs jogosultságod.', $wpError->get_error_message());
        }
        self::assertSame(0, $useCase->calls);
        self::assertSame([], $this->rows());
    }

    public function testAUserThatDoesNotExistIsRefused(): void
    {
        $useCase = new AdminProbe(function (): void {
            $this->insertRow('a');
        });

        $this->expectException(PermissionDenied::class);
        try {
            $this->runner->run($useCase, [], new UserActor(999999));
        } finally {
            self::assertSame(0, $useCase->calls);
        }
    }

    private static function calls(UseCase $useCase): int
    {
        return $useCase instanceof AdminProbe || $useCase instanceof PortalProbe ? $useCase->calls : -1;
    }
}
