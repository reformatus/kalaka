<?php

declare(strict_types=1);

namespace Kalaka\Foundation;

use Kalaka\Foundation\Api\Audit\Correlation;

/**
 * The one writer of PHP's error log, the only caller of `error_log()` (a unit test checks it).
 * A line carries what failed, the correlation ID and the whole exception chain, messages included:
 * foundation.md, "Failures", "The error log".
 *
 * @internal
 */
final class ErrorLog
{
    /** `Kaláka: <what> failed, correlation <id>: <chain>`, and `; <note>` when given. */
    public static function failure(string $what, \Throwable $error, ?string $note = null): void
    {
        $line = 'Kaláka: ' . $what . ' failed, correlation ' . Correlation::id() . ': ' . self::chain($error);
        if ($note !== null) {
            $line .= '; ' . $note;
        }
        self::write($line);
    }

    /** A condition that is not an exception: `Kaláka: <text>, correlation <id>`. */
    public static function notice(string $text): void
    {
        self::write('Kaláka: ' . $text . ', correlation ' . Correlation::id());
    }

    /** `<Class> (<code>): <message> <- <Class> (<code>): <message> …`, newlines collapsed. */
    public static function chain(\Throwable $error): string
    {
        $links = [];
        for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
            $links[] = $cause::class . ' (' . $cause->getCode() . '): ' . $cause->getMessage();
        }

        return implode(' <- ', $links);
    }

    private static function write(string $line): void
    {
        error_log(self::oneLine($line));
    }

    /**
     * Line breaks and the spaces around them become one space. Byte-wise, without `/u`, so a message
     * that is not valid UTF-8 still yields its line: ASCII bytes never occur inside a UTF-8 sequence.
     */
    public static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/[ \t]*[\r\n]+[ \t\r\n]*/', ' ', $text));
    }
}
