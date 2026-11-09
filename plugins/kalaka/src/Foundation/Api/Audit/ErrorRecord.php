<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Audit;

use Kalaka\Foundation\Api\Database\DatabaseError;
use Kalaka\Foundation\Database\Statements;
use Kalaka\Foundation\Api\Error\KalakaException;

/**
 * What a failed entry stores about the error (decision D6) and the one line the error log gets
 * when even that cannot be written (decision D13). Messages of previous exceptions are never
 * used: MariaDB's message can carry row values or a secret, and a wrapped exception's is not ours.
 *
 * @internal
 */
final class ErrorRecord
{
    public static function json(KalakaException $error, string $useCase): string
    {
        [$file, $line] = self::position($error);
        $previous = [];
        for ($cause = $error->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
            [$causeFile, $causeLine] = self::position($cause);
            $previous[] = [
                'class' => $cause::class,
                'code' => $cause->getCode(),
                'file' => self::relative($causeFile),
                'line' => $causeLine,
            ];
        }

        $json = wp_json_encode([
            'use_case' => $useCase,
            'class' => $error::class,
            'code' => $error->errorCode(),
            'status' => $error->status(),
            'message' => $error->getMessage(),
            'file' => self::relative($file),
            'line' => $line,
            'previous' => $previous,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('The error of a failed entry is not JSON-encodable.');
        }

        return $json;
    }

    /** No message, input, reason, IP address or user agent: only class names and numbers. */
    public static function fallbackLine(
        string $action,
        string $errorCode,
        string $correlationId,
        \Throwable $writeError,
    ): string {
        $line = 'Kaláka: audit entry not written: ' . $action . ' failed with ' . $errorCode
            . ', correlation ' . $correlationId . ': ' . $writeError::class;
        for ($cause = $writeError->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
            $line .= ' <- ' . $cause::class . ' (' . $cause->getCode() . ')';
        }

        return $line;
    }

    /**
     * Where the exception was thrown; for one made in `DatabaseError` or `Statements`, the first stack
     * frame outside both, so the entry names the code that ran the failing statement.
     *
     * @return array{string, int}
     */
    private static function position(\Throwable $error): array
    {
        $databaseError = (string) (new \ReflectionClass(DatabaseError::class))->getFileName();
        $skipped = [$databaseError, (string) (new \ReflectionClass(Statements::class))->getFileName()];
        if (in_array($error->getFile(), $skipped, true)) {
            foreach ($error->getTrace() as $frame) {
                if (isset($frame['file'], $frame['line']) && !in_array($frame['file'], $skipped, true)) {
                    return [$frame['file'], $frame['line']];
                }
            }
        }

        return [$error->getFile(), $error->getLine()];
    }

    private static function relative(string $file): string
    {
        $file = wp_normalize_path($file);
        $plugins = trailingslashit(wp_normalize_path(WP_PLUGIN_DIR));

        return str_starts_with($file, $plugins) ? substr($file, strlen($plugins)) : $file;
    }
}
