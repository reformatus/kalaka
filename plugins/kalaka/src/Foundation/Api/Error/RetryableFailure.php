<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Error;

final class RetryableFailure extends SystemFailure
{
    public function errorCode(): string
    {
        return 'kalaka_retryable_failure';
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
