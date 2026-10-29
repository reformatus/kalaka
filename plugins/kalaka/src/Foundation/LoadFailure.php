<?php

declare(strict_types=1);

namespace Kalaka\Foundation;

final readonly class LoadFailure
{
    public function __construct(public string $moduleId, public string $reason)
    {
    }
}
