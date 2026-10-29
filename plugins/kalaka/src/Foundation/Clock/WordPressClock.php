<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Clock;

use Kalaka\Foundation\Api\Clock;

final class WordPressClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return current_datetime();
    }
}
