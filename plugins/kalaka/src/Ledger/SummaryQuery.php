<?php

declare(strict_types=1);

namespace Kalaka\Ledger;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\Query\Query;
use Kalaka\Ledger\Api\ChargeLine;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\PayerSummary;
use Kalaka\Ledger\Api\Summary;
use Kalaka\Registrations\Api\RegistrationKey;

/** Reads the money of one registration per payer; nothing is stored. */
final class SummaryQuery extends Query
{
    /** One statement over the registration's lines, in ledger order; summed in PHP with brick/math. */
    public function of(RegistrationKey $registration): Summary
    {
        return $this->scope(function () use ($registration): Summary {
            $rows = $this->select(
                'ledger_line',
                'SELECT id, kind, payer, amount, currency, source_type, source_event_id, source_key FROM %i'
                . ' WHERE event_id = %d AND code = %s ORDER BY id',
                [$this->table('kalaka_ledger_lines'), $registration->eventId, $registration->code]
            );

            /** @var array<string, array{zero: Money, charged: ?Money, facts: ?Money}> $sums */
            $sums = [];
            $lines = [];
            foreach ($rows as $row) {
                $payer = (string) $row['payer'];
                $kind = LineKind::from((string) $row['kind']);
                $amount = Money::of((string) $row['amount'], Currency::from((string) $row['currency']));
                $sums[$payer] ??= ['zero' => Money::zero($amount->currency()), 'charged' => null, 'facts' => null];
                $part = $kind->isPaymentFact() ? 'facts' : 'charged';
                // A payer with lines in two currencies meets Money's mixed-currency guard on the sum.
                $sums[$payer][$part] = $sums[$payer][$part]?->plus($amount) ?? $amount;
                if (!$kind->isPaymentFact()) {
                    $lines[$payer][] = new ChargeLine(
                        (int) $row['id'],
                        $kind,
                        $amount,
                        new Subject(
                            (string) $row['source_type'],
                            $row['source_event_id'] === null ? null : (int) $row['source_event_id'],
                            (string) $row['source_key'],
                        ),
                    );
                }
            }

            ksort($sums, SORT_STRING);
            $payers = [];
            foreach ($sums as $payer => $sum) {
                $charged = $sum['charged'] ?? $sum['zero'];
                $paid = ($sum['facts'] ?? $sum['zero'])->negated();
                $payers[$payer] = new PayerSummary($payer, $charged, $paid, $charged->minus($paid));
            }

            return new Summary($payers, $lines);
        });
    }
}
