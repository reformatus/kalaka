<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\LedgerEntry;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Portal\LineLabels;
use Kalaka\Pricing\Api\LineName;
use Kalaka\Pricing\Api\LineNames;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * One payer's money table (`ParticipantMoney`, kept under its name: it is "a payer's money table", the
 * participant's by default) from one ledger statement of that payer and the names of its owed lines. A plain read
 * of the module Apis: it writes nothing and applies no access rule of its own, like `StatusRead::of()`; the blocks
 * reach it only through `forActive()`. Any payer's table is built by this one code, so a congregation table is
 * shaped exactly like the participant's. [UNAPPROVED N692]
 */
final class MoneyRead
{
    /** The method label of every `payment` and `refund` line: the automatic methods are online. */
    private const ONLINE = 'Online';

    public function __construct(
        private readonly Ledger $ledger = new Ledger(),
        private readonly LineNames $names = new LineNames(),
        private readonly LineLabels $labels = new LineLabels(),
    ) {
    }

    public function of(RegistrationKey $key, Currency $currency, string $payer = Payer::PARTICIPANT): ParticipantMoney
    {
        $statement = $this->ledger->statementOf($key, $payer, $currency);

        $balance = $statement->totals->balance;
        $overpaid = $balance->isNegative() && !$balance->countsAsZero();
        $due = $balance->roundedUpToPayableUnit();

        return new ParticipantMoney(
            $currency,
            $this->owed($key, $statement->owed, $currency),
            array_map(self::movement(...), $statement->movements),
            $statement->totals->charged,
            $statement->totals->paid,
            $balance,
            $overpaid ? null : ($due->isPositive() ? $due : Money::zero($currency)),
            $overpaid ? $balance->negated() : null,
        );
    }

    /**
     * One row per source, its lines summed (a re-price or a withdrawal writes differences, so a source is one
     * row, and a source that sums to zero is no row). The base price first, then the positive rows, then the
     * negative ones, each in the order of the source's first line.
     *
     * @param list<LedgerEntry> $entries
     * @return list<OwedRow>
     */
    private function owed(RegistrationKey $key, array $entries, Currency $currency): array
    {
        /** @var array<string, array{source: Subject, sum: Money}> $groups */
        $groups = [];
        foreach ($entries as $entry) {
            $source = $entry->source;
            $id = $source->type . "\n" . ($source->eventId ?? '') . "\n" . $source->key;
            $groups[$id] = ['source' => $source, 'sum' => ($groups[$id]['sum'] ?? Money::zero($currency))
                ->plus($entry->amount)];
        }
        $groups = array_filter(
            $groups,
            static fn (array $group): bool => $group['sum']->isPositive() || $group['sum']->isNegative()
        );
        if ($groups === []) {
            return [];
        }

        $sources = array_values(array_map(static fn (array $group): Subject => $group['source'], $groups));
        $names = $this->names->of($key, $sources);
        $base = [];
        $positive = [];
        $negative = [];
        foreach ($groups as $group) {
            $name = $names[$group['source']->key] ?? new LineName(LineName::FEE, LineLabels::FEE);
            $row = new OwedRow($this->labels->owedLabelOf($name), OwedKind::of($name), $group['sum']);
            if ($row->kind === OwedKind::Base) {
                $base[] = $row;
            } elseif ($row->amount->isNegative()) {
                $negative[] = $row;
            } else {
                $positive[] = $row;
            }
        }

        return [...$base, ...$positive, ...$negative];
    }

    /**
     * The movement a paid-side ledger line is, as the table lists it. The payment mail reads one line through it
     * (`MailMoney::movement()`), so the mail and the table use the same words. Not for a charge line.
     */
    public static function movement(LedgerEntry $entry): MovementRow
    {
        $refund = $entry->kind === LineKind::Refund || $entry->kind === LineKind::ManualRefund;
        $type = match (true) {
            $entry->correction => MovementType::Correction,
            $refund => MovementType::Refund,
            default => MovementType::Payment,
        };
        $direction = $entry->correction ? ($refund ? MovementDirection::Lowers : MovementDirection::Raises) : null;
        $method = $entry->kind->carriesMethod() ? $entry->method?->label() : self::ONLINE;

        return new MovementRow($type, $direction, $method, $entry->note, $entry->valueDay(), $entry->amount->negated());
    }
}
