<?php

declare(strict_types=1);

namespace Kalaka\Ledger;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Ledger\Api\LedgerEntry;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\PaymentMethod;

/** One row of the display reads' SELECT (a line left-joined with its note) as a `LedgerEntry`. */
final class EntryRow
{
    /** @param array<string, mixed> $row */
    public static function entry(array $row): LedgerEntry
    {
        return new LedgerEntry(
            (int) $row['id'],
            LineKind::from((string) $row['kind']),
            (string) $row['payer'],
            Money::of((string) $row['amount'], Currency::from((string) $row['currency'])),
            new Subject(
                (string) $row['source_type'],
                $row['source_event_id'] === null ? null : (int) $row['source_event_id'],
                (string) $row['source_key'],
            ),
            $row['value_date'] === null ? null : Instant::fromStorage((string) $row['value_date']),
            $row['paid_at'] === null ? null : Instant::fromStorage((string) $row['paid_at']),
            Instant::fromStorage((string) $row['recorded_at']),
            $row['method'] === null ? null : PaymentMethod::from((string) $row['method']),
            (int) $row['correction'] === 1,
            $row['note'] === null ? null : (string) $row['note'],
        );
    }
}
