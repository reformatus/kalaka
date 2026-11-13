<?php

declare(strict_types=1);

namespace Kalaka\Mail;

/**
 * The error of one attempt, as it is stored: on the delivery, in the audit diff of the use case that
 * records it and, after errors, on the pause, where the banner shows it. The reported text is kept as it
 * is (owner's ruling on S8-2); purging and retention come with the compliance work. Stored as
 * `<stage> <code>: <message> (correlation <id>)` on one line; the error log gets the same failure under the
 * same correlation ID.
 */
final readonly class DeliveryError
{
    /** Stages: before the claim (the address), rendering, and `wp_mail`. */
    public const ADDRESS = 'address';

    public const COMPOSE = 'compose';

    public const SEND = 'send';

    /**
     * @param string $stage one of the stage constants
     * @param string $code a short machine code: the `WP_Error` code or the exception class
     * @param ?string $message the reported text, as it is
     */
    public function __construct(
        public string $stage,
        public string $code,
        public ?string $message,
        public string $correlation,
    ) {
    }

    /** An exception's error: its class and its message. */
    public static function thrown(string $stage, \Throwable $error, string $correlation): self
    {
        return new self($stage, $error::class, $error->getMessage(), $correlation);
    }

    public function text(): string
    {
        $text = $this->stage . ' ' . $this->code;
        if ($this->message !== null && $this->message !== '') {
            $text .= ': ' . $this->message;
        }

        return self::oneLine($text) . ' (correlation ' . $this->correlation . ')';
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
