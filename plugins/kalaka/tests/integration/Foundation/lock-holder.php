<?php

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects -- a script RunnerIsolationTest runs in a process of its own

/*
 * The other transaction of RunnerIsolationTest. It locks row R, inserts row Y and prints "locked"; then
 * it waits until the given connection runs a `FOR UPDATE`, which waits for R, commits, and prints
 * "waited", or "no wait" when it gave up after 10 seconds. Arguments: the table, R's id, Y's label and
 * the waiting connection's thread id. The database comes from the environment: KALAKA_DB_HOST, _USER,
 * _PASSWORD and _NAME.
 */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

[, $table, $rowId, $label, $waiter] = $argv;
$connection = new mysqli(
    (string) getenv('KALAKA_DB_HOST'),
    (string) getenv('KALAKA_DB_USER'),
    (string) getenv('KALAKA_DB_PASSWORD'),
    (string) getenv('KALAKA_DB_NAME')
);
$quoted = '`' . str_replace('`', '``', $table) . '`';

$connection->begin_transaction();
$connection->query('SELECT id FROM ' . $quoted . ' WHERE id = ' . (int) $rowId . ' FOR UPDATE');
$insert = $connection->prepare('INSERT INTO ' . $quoted . ' (label) VALUES (?)');
$insert->bind_param('s', $label);
$insert->execute();
// The test starts its transaction only after this line.
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- the pipe to the test
fwrite(STDOUT, "locked\n");

$waited = false;
for ($poll = 0; $poll < 200 && !$waited; $poll++) {
    usleep(50000);
    // The waiter's statement is running while this transaction holds R: it waits for the lock.
    $result = $connection->query(
        'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = ' . (int) $waiter
        . " AND COMMAND = 'Query' AND INFO LIKE '%FOR UPDATE%'"
    );
    $waited = $result instanceof mysqli_result && (int) ($result->fetch_row()[0] ?? 0) > 0;
}

$connection->commit();
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- the pipe to the test
fwrite(STDOUT, $waited ? "waited\n" : "no wait\n");
