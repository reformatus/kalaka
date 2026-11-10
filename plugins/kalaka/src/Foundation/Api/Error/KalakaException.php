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
     * The code, the text a person sees and the status, with the request's correlation ID in the
     * data, so a client can match the error to the audit and the error log. The text stays plain: a
     * client that shows a 500 adds the `Hibaazonosító` line from `data.correlation` (see
     * `displayMessage()`).
     */
    public function toWpError(): \WP_Error
    {
        return new \WP_Error(
            $this->errorCode(),
            $this->publicMessage(),
            ['status' => $this->status(), 'correlation' => Correlation::id()]
        );
    }

    /**
     * The Hungarian text a person may see: the default text and the thrower's context, except for a
     * 500, whose context is technical and reaches only the error log and the audit (`getMessage()`).
     */
    public function publicMessage(): string
    {
        // [UNAPPROVED N1] A 500 shows only its default text.
        return $this->status() < 500 ? $this->getMessage() : $this->defaultMessage();
    }

    /**
     * The text a person sees. A 500 adds a second line with the short correlation ID, which matches
     * the audit and the error log by prefix; other errors are the person's to act on and show none.
     */
    public function displayMessage(): string
    {
        return $this->withIdLine($this->publicMessage());
    }

    /**
     * The text WP-CLI prints: as `displayMessage()`, but a 500 keeps its context, because the
     * console is the operator's.
     */
    public function operatorMessage(): string
    {
        // [UNAPPROVED N2] WP-CLI keeps a 500's context.
        return $this->withIdLine($this->getMessage());
    }

    /** The text, and for a 500 the `Hibaazonosító` line under it. */
    protected function withIdLine(string $text): string
    {
        if ($this->status() < 500) {
            return $text;
        }

        return $text . "\n" . self::idLine(Correlation::id());
    }

    /** `Hibaazonosító: <the first 8 hex digits of the correlation ID>`. */
    public static function idLine(string $correlationId): string
    {
        return 'Hibaazonosító: ' . substr($correlationId, 0, 8);
    }
}
