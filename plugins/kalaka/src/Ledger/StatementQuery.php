<?php

declare(strict_types=1);

namespace Kalaka\Ledger;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\Query\Query;
use Kalaka\Ledger\Api\LedgerEntry;
use Kalaka\Ledger\Api\PayerStatement;
use Kalaka\Ledger\Api\PayerSummary;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Reads one payer's side of one registration for display: its lines with their notes, one statement, and the
 * totals summed in PHP from those same rows so rows and totals always add up.
 */
final class StatementQuery extends Query
{
    public function of(RegistrationKey $registration, string $payer, Currency $currency): PayerStatement
    {
        return $this->scope(function () use ($registration, $payer, $currency): PayerStatement {
            $rows = $this->select(
                'ledger_line',
                'SELECT l.id, l.kind, l.payer, l.amount, l.currency, l.source_type, l.source_event_id,'
                . ' l.source_key, l.value_date, l.paid_at, l.recorded_at, l.method, l.correction, n.note'
                . ' FROM %i l LEFT JOIN %i n ON n.event_id = l.event_id AND n.line_id = l.id'
                . ' WHERE l.event_id = %d AND l.code = %s AND l.payer = %s ORDER BY l.id',
                [
                    $this->table('kalaka_ledger_lines'),
                    $this->table('kalaka_payment_notes'),
                    $registration->eventId,
                    $registration->code,
                    $payer,
                ]
            );

            $zero = Money::zero($currency);
            $charged = $zero;
            $facts = $zero;
            $owed = [];
            $movements = [];
            foreach ($rows as $row) {
                $entry = EntryRow::entry($row);
                if ($entry->kind->isPaymentFact()) {
                    $facts = $facts->plus($entry->amount);
                    $movements[] = $entry;
                } else {
                    $charged = $charged->plus($entry->amount);
                    $owed[] = $entry;
                }
            }
            // By value day (site time zone), then ledger order: PHP's sort is stable and the rows came by ID.
            usort(
                $movements,
                static fn (LedgerEntry $a, LedgerEntry $b): int => $a->valueDay() <=> $b->valueDay()
            );
            $paid = $facts->negated();

            return new PayerStatement(
                $payer,
                $owed,
                $movements,
                new PayerSummary($payer, $charged, $paid, $charged->minus($paid))
            );
        });
    }
}
