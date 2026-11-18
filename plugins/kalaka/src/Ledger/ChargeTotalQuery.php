<?php

declare(strict_types=1);

namespace Kalaka\Ledger;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\Query\Query;
use Kalaka\Ledger\Api\ChargeTotal;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Registrations\Api\RegistrationKey;

/** Sums the `charge` lines of one registration per source and payer; nothing is stored. */
final class ChargeTotalQuery extends Query
{
    /**
     * One statement over the registration's `charge` lines; `adjustment` lines never count.
     *
     * @return list<ChargeTotal> ordered by source, then payer
     */
    public function of(RegistrationKey $registration): array
    {
        return $this->scope(function () use ($registration): array {
            $rows = $this->select(
                'ledger_line',
                'SELECT source_type, source_event_id, source_key, payer, currency, SUM(amount) AS total,'
                . ' COUNT(*) AS line_count FROM %i WHERE event_id = %d AND code = %s AND kind = %s'
                . ' GROUP BY source_type, source_event_id, source_key, payer, currency'
                . ' ORDER BY source_type, source_event_id, source_key, payer, currency',
                [
                    $this->table('kalaka_ledger_lines'),
                    $registration->eventId,
                    $registration->code,
                    LineKind::Charge->value,
                ]
            );

            $totals = [];
            foreach ($rows as $row) {
                $totals[] = new ChargeTotal(
                    new Subject(
                        (string) $row['source_type'],
                        $row['source_event_id'] === null ? null : (int) $row['source_event_id'],
                        (string) $row['source_key'],
                    ),
                    (string) $row['payer'],
                    Money::of((string) $row['total'], Currency::from((string) $row['currency'])),
                    (int) $row['line_count'],
                );
            }

            return $totals;
        });
    }
}
