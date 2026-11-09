<?php

declare(strict_types=1);

namespace Kalaka\Ledger;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\Query\Query;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\PaymentFact;
use Kalaka\Registrations\Api\RegistrationKey;

/** Reads the payment-fact lines of one registration. */
final class PaymentFactQuery extends Query
{
    /**
     * One statement over the payment-fact kinds, ordered in PHP by `valueAt()`, then ID.
     *
     * @return list<PaymentFact>
     */
    public function of(RegistrationKey $registration): array
    {
        return $this->scope(function () use ($registration): array {
            $kinds = array_values(array_map(
                static fn (LineKind $kind): string => $kind->value,
                array_filter(LineKind::cases(), static fn (LineKind $kind): bool => $kind->isPaymentFact())
            ));
            $rows = $this->select(
                'ledger_line',
                'SELECT id, kind, payer, amount, currency, value_date, paid_at FROM %i'
                . ' WHERE event_id = %d AND code = %s AND kind IN ('
                . implode(', ', array_fill(0, count($kinds), '%s')) . ')',
                [$this->table('kalaka_ledger_lines'), $registration->eventId, $registration->code, ...$kinds]
            );

            $utc = new \DateTimeZone('UTC');
            $facts = [];
            foreach ($rows as $row) {
                $facts[] = new PaymentFact(
                    (int) $row['id'],
                    LineKind::from((string) $row['kind']),
                    (string) $row['payer'],
                    Money::of((string) $row['amount'], Currency::from((string) $row['currency'])),
                    $row['value_date'],
                    $row['paid_at'] === null ? null : new \DateTimeImmutable($row['paid_at'], $utc),
                );
            }
            usort(
                $facts,
                static fn (PaymentFact $a, PaymentFact $b): int => $a->valueAt() <=> $b->valueAt() ?: $a->id <=> $b->id
            );

            return $facts;
        });
    }
}
