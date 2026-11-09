<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Error\RetryableFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * `Context::holdsRegistration()`: a row held at the registration level, through every way the bases
 * take one, makes the registration its record maps to held; nothing else does.
 */
final class HoldsRegistrationTest extends RepositoryTestCase
{
    private TestItemRepository $held;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->held = new TestItemRepository('kalaka_test_items_other', LockLevel::Registration);
    }

    public function testAReadForUpdateHoldsTheRegistrationOfItsRecordOnlyOnceItIsLoaded(): void
    {
        $id = $this->seedMapped('kalaka_test_items_other', 12, '00A0');
        $key = new RegistrationKey(12, '00A0');

        $seen = $this->inRun(function (array $input, Context $context) use ($id, $key): array {
            $seen = [$context->holdsRegistration($key)];
            self::assertInstanceOf(TestItem::class, $this->held->findForUpdate($id, $context));
            $seen[] = $context->holdsRegistration($key);
            self::assertNull($this->held->findForUpdate($id + 1, $context));
            $seen[] = $context->holdsRegistration(new RegistrationKey(12, '00A2'));

            return $seen;
        });

        self::assertSame([false, true, false], $seen);
    }

    public function testAMultiRowReadForUpdateHoldsTheRegistrationOfEveryRow(): void
    {
        $this->seedMapped('kalaka_test_items_other', 12, '00A0', 'pair');
        $this->seedMapped('kalaka_test_items_other', 12, '00A2', 'pair');

        $seen = $this->inRun(function (array $input, Context $context): array {
            self::assertCount(2, $this->held->byLabelForUpdate('pair', $context));

            return [
                $context->holdsRegistration(new RegistrationKey(12, '00A0')),
                $context->holdsRegistration(new RegistrationKey(12, '00A2')),
            ];
        });

        self::assertSame([true, true], $seen);
    }

    public function testAnInsertAndAnUpdateHoldTheRegistrationAndANestedRunSharesWhatIsHeld(): void
    {
        $id = $this->seedMapped('kalaka_test_items_other', 12, '00B0');
        $nestedSaw = null;
        $nested = new NamedProbe(function (array $input, Context $context) use (&$nestedSaw): void {
            $nestedSaw = $context->holdsRegistration(new RegistrationKey(12, '00A0'));
            $this->held->upsert(new TestItem('nested', regEvent: 12, regCode: '00A2'), $context);
        });

        $seen = $this->inRun(function (array $input, Context $context) use ($id, $nested): array {
            $item = $this->held->find($id);
            self::assertInstanceOf(TestItem::class, $item);
            $item->label = 'updated';
            $this->held->upsert($item, $context);
            $this->held->upsert(new TestItem('inserted', regEvent: 12, regCode: '00A0'), $context);
            $context->run($nested, []);

            return [
                $context->holdsRegistration(new RegistrationKey(12, '00A0')),
                $context->holdsRegistration(new RegistrationKey(12, '00A2')),
                $context->holdsRegistration(new RegistrationKey(12, '00B0')),
            ];
        });

        self::assertTrue($nestedSaw);
        self::assertSame([true, true, true], $seen);
    }

    public function testARowBelowTheRegistrationLevelHoldsNoRegistration(): void
    {
        $id = $this->seedMapped('kalaka_test_items', 12, '00A0');
        $key = new RegistrationKey(12, '00A0');

        $seen = $this->inRun(function (array $input, Context $context) use ($id, $key): bool {
            $this->lockRegistrations($context, $key);
            self::assertInstanceOf(TestItem::class, $this->items->findForUpdate($id, $context));

            return $context->holdsRegistration($key);
        });

        self::assertFalse($seen);
    }

    public function testARetriedAttemptStartsHoldingNothing(): void
    {
        $id = $this->seedMapped('kalaka_test_items_other', 12, '00A0');
        $key = new RegistrationKey(12, '00A0');
        $seen = [];
        $useCase = new AdminProbe(function (array $input, Context $context, int $call) use ($id, $key, &$seen): void {
            $seen[] = $context->holdsRegistration($key);
            if ($call === 1) {
                $this->held->findForUpdate($id, $context);
                throw new RetryableFailure();
            }
        });

        $this->runner->run($useCase, [], new CliActor(null));

        self::assertSame([false, false], $seen);
    }

    private function seedMapped(string $table, int $event, string $code, string $label = 'mapped'): int
    {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . $table,
            ['label' => $label, 'reg_event' => $event, 'reg_code' => $code, 'version' => 1]
        );

        return $wpdb->insert_id;
    }
}
