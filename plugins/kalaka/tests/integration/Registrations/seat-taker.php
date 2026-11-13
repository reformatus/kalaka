<?php

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects -- a script SubmitRaceTest runs in a process of its own

/*
 * The other submit of SubmitRaceTest, in SQL: it locks the event row as a submit does, takes the last
 * place with a `registered` registration and prints "locked"; then it waits until the given connection
 * runs a `FOR UPDATE`, which waits for the event row, commits, and prints "waited", or "no wait" when it
 * gave up after 10 seconds. Arguments: the table prefix, the event ID, the code and the waiting
 * connection's thread id. The database comes from the environment: KALAKA_DB_HOST, _USER, _PASSWORD
 * and _NAME.
 */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

[, $prefix, $eventId, $code, $waiter] = $argv;
$connection = new mysqli(
    (string) getenv('KALAKA_DB_HOST'),
    (string) getenv('KALAKA_DB_USER'),
    (string) getenv('KALAKA_DB_PASSWORD'),
    (string) getenv('KALAKA_DB_NAME')
);
$events = '`' . str_replace('`', '``', $prefix . 'kalaka_events') . '`';
$registrations = '`' . str_replace('`', '``', $prefix . 'kalaka_registrations') . '`';

$connection->begin_transaction();
$connection->query('SELECT id FROM ' . $events . ' WHERE id = ' . (int) $eventId . ' FOR UPDATE');
$insert = $connection->prepare(
    'INSERT INTO ' . $registrations . ' (event_id, code, version, status, secret, created_at, registered_at)'
    . " VALUES (?, ?, 1, 'registered', 'secret', '2026-11-30 07:59:59.000000', '2026-11-30 07:59:59.000000')"
);
$event = (int) $eventId;
$insert->bind_param('is', $event, $code);
$insert->execute();
// The test starts its submit only after this line.
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- the pipe to the test
fwrite(STDOUT, "locked\n");

$waited = false;
for ($poll = 0; $poll < 200 && !$waited; $poll++) {
    usleep(50000);
    $result = $connection->query(
        'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = ' . (int) $waiter
        . " AND COMMAND = 'Query' AND INFO LIKE '%FOR UPDATE%'"
    );
    $waited = $result instanceof mysqli_result && (int) ($result->fetch_row()[0] ?? 0) > 0;
}

$connection->commit();
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- the pipe to the test
fwrite(STDOUT, $waited ? "waited\n" : "no wait\n");
