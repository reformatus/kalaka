<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\UseCase\Runner;
use WP_UnitTestCase;

final class BootTest extends WP_UnitTestCase
{
    public function testBootRecordsNoLoadFailures(): void
    {
        $state = LoadState::current();

        self::assertSame([], $state->failures);
        self::assertTrue($state->isOpen());
    }

    public function testBootPublishesTheRunner(): void
    {
        self::assertInstanceOf(Runner::class, Runner::current());
    }
}
