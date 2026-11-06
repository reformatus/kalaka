<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Database;

use Kalaka\Foundation\Api\Error\KalakaException;
use Kalaka\Foundation\Api\Error\RetryableFailure;
use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * Turns the database's last error into a Kalaka exception: engineering.md, "Database".
 * The texts stay the classes' defaults; the number and MariaDB's message travel in `previous`.
 */
final class DatabaseError
{
    private const DEADLOCK = 1213;
    private const LOCK_WAIT_TIMEOUT = 1205;
    private const RECORD_CHANGED = 1020;
    private const DUPLICATE_KEY = 1062;

    /** Call right after a `$wpdb` statement; does nothing when the statement succeeded. */
    public static function check(\wpdb $wpdb, bool $referenceCodeInsert = false): void
    {
        // `dbh` is protected, but wpdb exposes it through __get().
        $connection = $wpdb->__get('dbh');
        $number = $connection instanceof \mysqli ? mysqli_errno($connection) : 0;
        if ($number === 0 && $wpdb->last_error === '') {
            return;
        }

        $error = self::exceptionFor($number, $wpdb->last_error, $referenceCodeInsert);
        throw $error;
    }

    /** The pure mapping from a MariaDB error to what is thrown. */
    public static function exceptionFor(int $number, string $message, bool $referenceCodeInsert): KalakaException
    {
        $previous = new \RuntimeException('Database error ' . $number . ': ' . $message, $number);

        $retryable = match ($number) {
            self::DEADLOCK, self::LOCK_WAIT_TIMEOUT, self::RECORD_CHANGED => true,
            self::DUPLICATE_KEY => $referenceCodeInsert,
            default => false,
        };

        return $retryable ? new RetryableFailure(null, $previous) : new SystemFailure(null, $previous);
    }
}
