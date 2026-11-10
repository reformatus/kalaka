<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Foundation\ErrorLogFixture;

/** A file that writes PHP's error log itself: the error-log check must find it. */
final class LogsDirectly
{
    public function run(): void
    {
        \error_log('the fixture the error-log check must find');
    }
}
