<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Error;

use Kalaka\Foundation\Api\Audit\Correlation;

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

    /**
     * The code, the Hungarian text and the status, with the request's correlation ID in the data, so
     * a client can match the error to the audit and the error log. The text stays plain: a client
     * that shows a 500 adds the `Hibaazonosító` line from `data.correlation` (see `displayMessage()`).
     */
    public function toWpError(): \WP_Error
    {
        return new \WP_Error(
            $this->errorCode(),
            $this->getMessage(),
            ['status' => $this->status(), 'correlation' => Correlation::id()]
        );
    }

    /**
     * The text a person sees. A 500 adds a second line with the short correlation ID, which matches
     * the audit and the error log by prefix; other errors are the person's to act on and show none.
     */
    public function displayMessage(): string
    {
        if ($this->status() < 500) {
            return $this->getMessage();
        }

        return $this->getMessage() . "\n" . self::idLine(Correlation::id());
    }

    /** `Hibaazonosító: <the first 8 hex digits of the correlation ID>`. */
    public static function idLine(string $correlationId): string
    {
        return 'Hibaazonosító: ' . substr($correlationId, 0, 8);
    }
}
