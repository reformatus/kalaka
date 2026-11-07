<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Foundation;

use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Foundation\Api\UseCase\Runner;
use PHPUnit\Framework\TestCase;

final class RunnerCurrentTest extends TestCase
{
    public function testCurrentThrowsBeforeFoundationHasBooted(): void
    {
        $this->expectException(\LogicException::class);
        Runner::current();
    }

    public function testLockLevelsFollowTheLockOrder(): void
    {
        $values = array_map(static fn (LockLevel $level): int => $level->value, LockLevel::cases());

        self::assertSame(
            ['Event', 'Quota', 'Registration', 'Other'],
            array_map(static fn (LockLevel $l): string => $l->name, LockLevel::cases())
        );
        self::assertSame($values, array_values(array_unique($values)));
        $sorted = $values;
        sort($sorted);
        self::assertSame($sorted, $values);
    }
}
