<?php

declare(strict_types=1);

namespace Kalaka\Mail;

use Kalaka\Foundation\Api\Error\KalakaException;

/**
 * The error of one attempt, as it is stored: on the delivery, in the audit diff of the use case that
 * records it and, after errors, on the pause. Every e-mail-shaped piece of text is masked, because the
 * audit is kept for good; the full text goes only to the error log, under the same correlation ID.
 * Stored as `<stage> <code>: <message> (correlation <id>)`.
 * // [UNAPPROVED S8-2] the stored error is masked; the raw text only reaches the error log.
 */
final readonly class DeliveryError
{
    /** What an e-mail-shaped substring becomes. */
    public const MASK = '[cím]';

    /** Stages: before the claim (the address), rendering, and `wp_mail`. */
    public const ADDRESS = 'address';

    public const COMPOSE = 'compose';

    public const SEND = 'send';

    private const EMAIL_SHAPED = '/[^\s<>()\[\]{},;:"\'`]+@[^\s<>()\[\]{},;:"\'`]+/u';

    public string $code;

    public ?string $message;

    /**
     * @param string $stage one of the stage constants
     * @param string $code a short machine code: the `WP_Error` code or the exception class
     * @param ?string $message the reported text; masked here
     */
    public function __construct(public string $stage, string $code, ?string $message, public string $correlation)
    {
        $this->code = self::mask($code);
        $this->message = $message === null ? null : self::mask($message);
    }

    /**
     * An exception's error: a Kaláka exception keeps its own (Hungarian) message, any other only its
     * class, as a failed audit entry would.
     */
    public static function thrown(string $stage, \Throwable $error, string $correlation): self
    {
        $message = $error instanceof KalakaException ? $error->getMessage() : null;

        return new self($stage, $error::class, $message, $correlation);
    }

    public function text(): string
    {
        $text = $this->stage . ' ' . $this->code;
        if ($this->message !== null && $this->message !== '') {
            $text .= ': ' . $this->message;
        }

        return self::oneLine($text) . ' (correlation ' . $this->correlation . ')';
    }

    /** Replaces every e-mail-shaped substring with `[cím]`. */
    public static function mask(string $text): string
    {
        return (string) preg_replace(self::EMAIL_SHAPED, self::MASK, $text);
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
