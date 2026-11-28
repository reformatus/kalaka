<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\ActKeys;
use Kalaka\Foundation\Api\Error\IdempotencyConflict;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;

/** The retry key of a staff act: recall and remember inside a run. */
final class ActKeysTest extends AuditTestCase
{
    private const ACT = 'kalaka/unconfirm';

    private RegistrationKey $one;

    private RegistrationKey $two;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->one = new RegistrationKey(1, '00A0');
        $this->two = new RegistrationKey(1, '12A3');
        $this->seedRegistrations($this->one, $this->two);
    }

    /** @param \Closure(Context, ActKeys): mixed $work */
    private function inRun(\Closure $work): mixed
    {
        $probe = new AdminProbe(fn (array $input, Context $context): mixed => $work($context, new ActKeys()));

        return $this->runner->run($probe, [], new CliActor(null));
    }

    public function testAFreshKeyRecallsNullAndIsThenRemembered(): void
    {
        $input = ['status' => 'x', 'idempotency_key' => 'k1'];

        $first = $this->inRun(function (Context $context, ActKeys $keys) use ($input): ?array {
            $this->lockRegistrations($context, $this->one);
            $recalled = $keys->recall($context, $this->one, self::ACT, 'k1', $input);
            $keys->remember($context, $this->one, self::ACT, 'k1', $input, ['ok' => true, 'n' => 'é']);

            return $recalled;
        });
        self::assertNull($first);

        $again = $this->inRun(function (Context $context, ActKeys $keys) use ($input): ?array {
            $this->lockRegistrations($context, $this->one);

            return $keys->recall($context, $this->one, self::ACT, 'k1', $input);
        });
        self::assertSame(['ok' => true, 'n' => 'é'], $again);
    }

    public function testTheHashIgnoresTheKeyThePreviewAndTheOrderOfKeys(): void
    {
        $stored = ['b' => ['y' => 1, 'x' => [3, 1]], 'a' => 'v', 'idempotency_key' => 'k2'];
        $this->inRun(function (Context $context, ActKeys $keys) use ($stored): void {
            $this->lockRegistrations($context, $this->one);
            $keys->remember($context, $this->one, self::ACT, 'k2', $stored, ['done' => 1]);
        });

        $reordered = ['a' => 'v', 'b' => ['x' => [3, 1], 'y' => 1], 'preview' => false, 'idempotency_key' => 'other'];
        $result = $this->inRun(function (Context $context, ActKeys $keys) use ($reordered): ?array {
            $this->lockRegistrations($context, $this->one);

            return $keys->recall($context, $this->one, self::ACT, 'k2', $reordered);
        });
        self::assertSame(['done' => 1], $result);
    }

    public function testADifferentInputUnderTheSameKeyIsAConflict(): void
    {
        $this->inRun(function (Context $context, ActKeys $keys): void {
            $this->lockRegistrations($context, $this->one);
            $keys->remember($context, $this->one, self::ACT, 'k3', ['a' => 1], ['done' => 1]);
        });

        try {
            $this->inRun(function (Context $context, ActKeys $keys): void {
                $this->lockRegistrations($context, $this->one);
                $keys->recall($context, $this->one, self::ACT, 'k3', ['a' => 2]);
            });
            self::fail('A conflict was expected.');
        } catch (IdempotencyConflict $e) {
            self::assertSame(409, $e->status());
            self::assertSame('kalaka_idempotency_conflict', $e->errorCode());
        }
    }

    public function testANullKeyNeitherRecallsNorStores(): void
    {
        $this->inRun(function (Context $context, ActKeys $keys): void {
            $this->lockRegistrations($context, $this->one);
            self::assertNull($keys->recall($context, $this->one, self::ACT, null, ['a' => 1]));
            $keys->remember($context, $this->one, self::ACT, null, ['a' => 1], ['done' => 1]);
        });

        global $wpdb;
        self::assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_act_keys"));
    }

    public function testAPreviewNeverStoresNorRecalls(): void
    {
        $this->inRun(function (Context $context, ActKeys $keys): void {
            $this->lockRegistrations($context, $this->one);
            $preview = ['a' => 1, 'preview' => true];
            $keys->remember($context, $this->one, self::ACT, 'k4', $preview, ['done' => 1]);
            self::assertNull($keys->recall($context, $this->one, self::ACT, 'k4', $preview));
        });

        global $wpdb;
        self::assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_act_keys"));
    }

    public function testTheKeyIsPerRegistration(): void
    {
        $this->inRun(function (Context $context, ActKeys $keys): void {
            $this->lockRegistrations($context, $this->one, $this->two);
            $keys->remember($context, $this->one, self::ACT, 'k5', ['a' => 1], ['for' => 'one']);
            self::assertNull($keys->recall($context, $this->two, self::ACT, 'k5', ['a' => 2]));
            $keys->remember($context, $this->two, self::ACT, 'k5', ['a' => 2], ['for' => 'two']);
        });

        $result = $this->inRun(function (Context $context, ActKeys $keys): ?array {
            $this->lockRegistrations($context, $this->two);

            return $keys->recall($context, $this->two, self::ACT, 'k5', ['a' => 2]);
        });
        self::assertSame(['for' => 'two'], $result);
    }

    public function testTheSchemaFragment(): void
    {
        self::assertSame([], ActKeys::schemaFragment(false)['required']);
        self::assertSame(['idempotency_key'], ActKeys::schemaFragment(true)['required']);
        self::assertSame(
            '^[a-z0-9_:.-]{1,100}\\z',
            ActKeys::schemaFragment(true)['properties']['idempotency_key']['pattern']
        );
    }
}
