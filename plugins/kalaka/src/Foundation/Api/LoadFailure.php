<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api;

final readonly class LoadFailure
{
    /** @param string $moduleId The module, or the hook when no single module failed. */
    public function __construct(public string $moduleId, public string $reason)
    {
    }
}
