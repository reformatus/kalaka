<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\LoadState;
use WP_UnitTestCase;

final class BootTest extends WP_UnitTestCase
{
    public function testBootRecordsNoLoadFailures(): void
    {
        $state = LoadState::current();

        self::assertSame([], $state->failures);
        self::assertTrue($state->isOpen());
    }
}
