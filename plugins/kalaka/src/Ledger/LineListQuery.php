<?php

declare(strict_types=1);

namespace Kalaka\Ledger;

use Kalaka\Foundation\Api\Query\Query;
use Kalaka\Ledger\Api\LedgerEntry;
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
                $entries[] = EntryRow::entry($row);
            }

            return $entries;
        });
    }
}
