<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Foundation\Api\Audit\Correlation;
use Kalaka\Foundation\Api\Error\KalakaException;

/**
 * A form that cannot be used right now (`Forms::refusals()`), with the codes of the requirements it
 * misses in the error data, in `refusals()` order. Not audited: like an answer error, it is the
 * person's to act on, and a closed form must not fill the audit.
 */
final class FormRefused extends KalakaException
{
    /** @param non-empty-list<AccessRefusal> $refusals */
    public function __construct(public readonly array $refusals, ?string $context = null, ?\Throwable $previous = null)
    {
        parent::__construct($context, $previous);
    }

    protected function defaultMessage(): string
    {
        return 'Ez az űrlap most nem küldhető be.';
    }

    public function errorCode(): string
    {
        return 'kalaka_form_refused';
    }

    public function status(): int
    {
        return 403;
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
            'refusals' => array_map(static fn (AccessRefusal $refusal): string => $refusal->value, $this->refusals),
        ]);
    }
}
