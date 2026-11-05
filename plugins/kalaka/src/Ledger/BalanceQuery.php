<?php

declare(strict_types=1);

namespace Kalaka\Ledger;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\Query\Query;
use Kalaka\Ledger\Api\Balances;
use Kalaka\Registrations\Api\RegistrationKey;

/** Computes the balances of one registration; nothing is stored. */
final class BalanceQuery extends Query
{
    /** One statement over the registration's lines, summed per payer and currency. */
    public function of(RegistrationKey $registration): Balances
    {
        return $this->scope(function () use ($registration): Balances {
            $rows = $this->select(
                'ledger_line',
                'SELECT payer, currency, SUM(amount) AS balance FROM %i WHERE event_id = %d AND code = %s'
                . ' GROUP BY payer, currency ORDER BY payer, currency',
                [$this->table('kalaka_ledger_lines'), $registration->eventId, $registration->code]
            );

            // A payer with lines in two currencies meets Money's mixed-currency guard on the sum.
            $byPayer = [];
            foreach ($rows as $row) {
                $payer = (string) $row['payer'];
                $balance = Money::of((string) $row['balance'], Currency::from((string) $row['currency']));
                $byPayer[$payer] = isset($byPayer[$payer]) ? $byPayer[$payer]->plus($balance) : $balance;
            }

            return new Balances($byPayer);
        });
    }
}
