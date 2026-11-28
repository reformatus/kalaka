<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration;

/** Captures what PHP writes to its error log while the body runs. */
trait LogCapture
{
    /**
     * The log lines written while the body runs.
     *
     * @param \Closure(): mixed $body
     * @return array{string, mixed}
     */
    protected function captureLog(\Closure $body): array
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'kalaka-log-');
        $previous = ini_set('error_log', $file);
        try {
            $result = $body();
        } finally {
            ini_set('error_log', (string) $previous);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the captured error log, a temporary file
        $log = (string) file_get_contents($file);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removes the captured error log, a temporary file
        unlink($file);

        return [$log, $result];
    }
}
