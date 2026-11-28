<?php

declare(strict_types=1);

namespace Kalaka\Ledger;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\Query\Query;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Ledger\Api\LedgerEntry;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\PaymentMethod;
use Kalaka\Registrations\Api\RegistrationKey;

/** Reads the lines of one registration for display, each with its note: one statement. */
final class LineListQuery extends Query
{
    /** @return list<LedgerEntry> newest first (by ID) */
    public function of(RegistrationKey $registration): array
    {
        return $this->scope(function () use ($registration): array {
            $rows = $this->select(
                'ledger_line',
                'SELECT l.id, l.kind, l.payer, l.amount, l.currency, l.source_type, l.source_event_id,'
                . ' l.source_key, l.value_date, l.paid_at, l.recorded_at, l.method, l.correction, n.note'
                . ' FROM %i l LEFT JOIN %i n ON n.event_id = l.event_id AND n.line_id = l.id'
                . ' WHERE l.event_id = %d AND l.code = %s ORDER BY l.id DESC',
                [
                    $this->table('kalaka_ledger_lines'),
                    $this->table('kalaka_payment_notes'),
                    $registration->eventId,
                    $registration->code,
                ]
            );

            $entries = [];
            foreach ($rows as $row) {
                $entries[] = new LedgerEntry(
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

            return $entries;
        });
    }
}
