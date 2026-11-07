<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Error\RetryableFailure;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;

/** The registrations a run's writes touch, for the evaluation. */
final class RepositoryTouchedTest extends RepositoryTestCase
{
    /**
     * @param list<RegistrationKey> $keys
     * @return list<string>
     */
    private static function codes(array $keys): array
    {
        return array_map(static fn (RegistrationKey $key): string => $key->eventId . '/' . $key->code, $keys);
    }

    public function testEveryKindOfWriteMarksItsRegistrationOnceAndNestedRunsCount(): void
    {
        $touched = null;
        $nested = new NamedProbe(function (array $input, Context $context): void {
            $this->items->upsert(new TestItem('nested', regEvent: 12, regCode: '00A1'), $context);
        });

        $this->inRun(function (array $input, Context $context) use (&$touched, $nested): void {
            $item = $this->items->upsert(new TestItem('one', regEvent: 12, regCode: '00A0'), $context);
            $item->label = 'two';
            $this->items->upsert($item, $context);
            $this->logs->insert(new TestLog('line', 12, '00A0'), $context);
            $this->logs->insert(new TestLog('unmapped'), $context);
            $this->items->upsert(new TestItem('unmapped'), $context);
            $context->run($nested, []);
            $touched = $context->touchedRegistrations();
        });

        self::assertIsArray($touched);
        self::assertSame(['12/00A0', '12/00A1'], self::codes($touched));
    }

    public function testARetriedAttemptStartsWithAnEmptySet(): void
    {
        $touched = null;
        $useCase = new AdminProbe(function (array $input, Context $context, int $call) use (&$touched): void {
            if ($call === 1) {
                $this->items->upsert(new TestItem('first attempt', regEvent: 12, regCode: '00A2'), $context);
                throw new RetryableFailure();
            }
            $this->items->upsert(new TestItem('second attempt', regEvent: 12, regCode: '00A3'), $context);
            $touched = $context->touchedRegistrations();
        });

        $this->runner->run($useCase, [], new CliActor(null));

        self::assertSame(2, $useCase->calls);
        self::assertIsArray($touched);
        self::assertSame(['12/00A3'], self::codes($touched));
    }

    public function testAContextKeptAfterItsRunRefusesToMarkARegistration(): void
    {
        $kept = null;
        $this->inRun(static function (array $input, Context $context) use (&$kept): void {
            $kept = $context;
        });
        self::assertInstanceOf(Context::class, $kept);

        $this->expectException(SystemFailure::class);
        $kept->markTouched(new RegistrationKey(12, '00A0'));
    }
}
