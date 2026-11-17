<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Foundation\Api\Audit\Correlation;
use Kalaka\Foundation\Api\Error\KalakaException;

/**
 * Too many attempts from one client in the current window (S14, spam protection): 429, with the seconds
 * until the window ends in the data (`retry_after`), which the form's routes send as `Retry-After`. Its own
 * code (`kalaka_rate_limited`) lets the page show it as its own case. Not audited: it is refused before
 * any use case, and a flood must not fill the audit.
 *
 * [UNAPPROVED #196 N5] the code, the status and `Retry-After`.
 */
final class RateLimited extends KalakaException
{
    public const CODE = 'kalaka_rate_limited';

    /** @param positive-int $retryAfter seconds until another attempt is counted again */
    public function __construct(public readonly int $retryAfter, ?string $context = null, ?\Throwable $previous = null)
    {
        parent::__construct($context, $previous);
    }

    protected function defaultMessage(): string
    {
        // [UNAPPROVED #196 T1] the refusal's text; empty until the owner approves one, so the page shows the code.
        return '';
    }

    public function errorCode(): string
    {
        return self::CODE;
    }

    public function status(): int
    {
        return 429;
    }

    public function isAudited(): bool
    {
        return false;
    }

    public function toWpError(): \WP_Error
    {
        return new \WP_Error($this->errorCode(), $this->getMessage(), [
            'status' => $this->status(),
            'correlation' => Correlation::id(),
            'retry_after' => $this->retryAfter,
        ]);
    }
}
