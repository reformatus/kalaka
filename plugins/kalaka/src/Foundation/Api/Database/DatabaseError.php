<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Database;

use Kalaka\Foundation\Api\Error\KalakaException;
use Kalaka\Foundation\Api\Error\RetryableFailure;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Database\BinlogCheck;
use Kalaka\Foundation\ErrorLog;

/**
 * Turns the database's last error into a Kalaka exception: engineering.md, "Database".
 * The texts stay the classes' defaults, except for error 1665, which carries `BinlogCheck::REFUSAL`;
 * the number and MariaDB's message travel in `previous`.
 */
final class DatabaseError
{
    private const DEADLOCK = 1213;
    private const LOCK_WAIT_TIMEOUT = 1205;
    private const RECORD_CHANGED = 1020;
    private const DUPLICATE_KEY = 1062;
    private const GONE_AWAY = 2006;
    private const BINLOG_STATEMENT_READ_COMMITTED = 1665;

    /**
     * Call right after a `$wpdb` statement; does nothing when the statement succeeded. Inside a use-case
     * attempt the error is recorded as the run's failure before it is thrown. Inside an attempt, a
     * connection other than the one it began on means wpdb reconnected and re-sent the statement: the
     * transaction is gone, so that is a retryable failure, whatever the statement's own outcome.
     */
    public static function check(\wpdb $wpdb, bool $referenceCodeInsert = false): void
    {
        // `dbh` is protected, but wpdb exposes it through __get().
        $connection = $wpdb->__get('dbh');
        self::checkConnection($connection);
        $number = $connection instanceof \mysqli ? mysqli_errno($connection) : 0;
        if ($number === 0 && $wpdb->last_error === '') {
            return;
        }

        $error = self::exceptionFor($number, $wpdb->last_error, $referenceCodeInsert);
        Runner::databaseFailed($error);
        throw $error;
    }

    private static function checkConnection(mixed $connection): void
    {
        $run = Runner::activeRun();
        if ($run === null || $run->threadId === null || !$connection instanceof \mysqli) {
            return;
        }
        if ($connection->thread_id === $run->threadId) {
            return;
        }

        if ($run->failure === null) {
            // Once a failure is recorded only reads still reach the database: the first re-sent statement counts.
            ErrorLog::notice(
                'reconnect inside ' . $run->useCase . '; the statement wpdb re-sent may have committed on its own'
            );
        }
        $previous = new \RuntimeException(
            'Database error ' . self::GONE_AWAY . ': the connection changed inside a run.',
            self::GONE_AWAY,
        );
        $error = new RetryableFailure(null, $previous);
        Runner::databaseFailed($error);
        throw $error;
    }

    /** The pure mapping from a MariaDB error to what is thrown. */
    public static function exceptionFor(int $number, string $message, bool $referenceCodeInsert): KalakaException
    {
        $previous = new \RuntimeException('Database error ' . $number . ': ' . $message, $number);

        if ($number === self::BINLOG_STATEMENT_READ_COMMITTED) {
            // Binary logging with binlog_format=STATEMENT refuses writes under READ COMMITTED: not retried.
            return new SystemFailure(BinlogCheck::REFUSAL, $previous);
        }

        $retryable = match ($number) {
            self::DEADLOCK, self::LOCK_WAIT_TIMEOUT, self::RECORD_CHANGED => true,
            self::DUPLICATE_KEY => $referenceCodeInsert,
            default => false,
        };

        return $retryable ? new RetryableFailure(null, $previous) : new SystemFailure(null, $previous);
    }
}
