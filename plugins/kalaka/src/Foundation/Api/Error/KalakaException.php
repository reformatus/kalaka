<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Error;

abstract class KalakaException extends \RuntimeException
{
    public function __construct(?string $context = null, ?\Throwable $previous = null)
    {
        $message = $this->defaultMessage();
        if ($context !== null) {
            $message .= ' ' . $context;
        }
        parent::__construct($message, 0, $previous);
    }

    abstract protected function defaultMessage(): string;

    /** WP_Error code. */
    abstract public function errorCode(): string;

    abstract public function status(): int;

    public function isRetryable(): bool
    {
        return false;
    }

    public function isAudited(): bool
    {
        return true;
    }

    public function toWpError(): \WP_Error
    {
        return new \WP_Error($this->errorCode(), $this->getMessage(), ['status' => $this->status()]);
    }
}
