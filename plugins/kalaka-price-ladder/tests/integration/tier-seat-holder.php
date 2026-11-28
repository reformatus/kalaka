<?php

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects -- a script TierSeatsRaceTest runs in a process of its own

/*
 * The winner of TierSeatsRaceTest, in SQL: it locks the tier row as a seat is taken (`FOR UPDATE`), takes the
 * last seat of the tier for the given registration and prints "locked"; then it waits until the given
 * connection waits for a lock (its `FOR UPDATE` of the tier row), commits, and prints "waited", or "no wait"
 * when it gave up after 10 seconds. Arguments: the table prefix, the event ID, the tier key, the code and the
 * waiting connection's thread id. The database comes from the environment: KALAKA_DB_HOST, _USER, _PASSWORD
 * and _NAME.
 */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

[, $prefix, $eventId, $tierKey, $code, $waiter] = $argv;
$connection = new mysqli(
    (string) getenv('KALAKA_DB_HOST'),
    (string) getenv('KALAKA_DB_USER'),
    (string) getenv('KALAKA_DB_PASSWORD'),
    (string) getenv('KALAKA_DB_NAME')
);
$tiers = '`' . str_replace('`', '``', $prefix . 'kalaka_price_ladder_tiers') . '`';
$seats = '`' . str_replace('`', '``', $prefix . 'kalaka_seats') . '`';

$connection->begin_transaction();
$connection->query(
    'SELECT seat_limit FROM ' . $tiers . ' WHERE event_id = ' . (int) $eventId
    . " AND tier_key = '" . $connection->real_escape_string($tierKey) . "' FOR UPDATE"
);
$pool = 'tier.' . $tierKey;
$insert = $connection->prepare(
    'INSERT INTO ' . $seats . ' (event_id, code, pool, version, taken_at, act)'
    . " VALUES (?, ?, ?, 1, '2026-11-30 07:59:59.000000', 'submit')"
);
$event = (int) $eventId;
$insert->bind_param('iss', $event, $code, $pool);
$insert->execute();
// The test starts its submit only after this line.
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- the pipe to the test
fwrite(STDOUT, "locked\n");

$waited = false;
for ($poll = 0; $poll < 200 && !$waited; $poll++) {
    usleep(50000);
    $result = $connection->query(
        'SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = ' . (int) $waiter
        . " AND trx_state = 'LOCK WAIT'"
    );
    $waited = $result instanceof mysqli_result && (int) ($result->fetch_row()[0] ?? 0) > 0;
}

$connection->commit();
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- the pipe to the test
fwrite(STDOUT, $waited ? "waited\n" : "no wait\n");
