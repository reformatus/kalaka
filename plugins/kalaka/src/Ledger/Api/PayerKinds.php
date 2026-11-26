<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The payer kinds that apply to an event (money.md, "Ledger": "extensions register other payer kinds"), and the one
 * rule for a registration's payers: `payersOf()` lists them for the admin detail read and the participant status read
 * (Portal's `StatusRead`), and `mayRecordFor()` tests membership for the record use cases. [UNAPPROVED Shapes]
 *
 * Core declares the participant; an extension declares another through a `PayerKindSource` (the congregation share,
 * S29), and this read adds the kinds that apply to the event. A source that throws fails the record use case (inside
 * the transaction, so the run rolls back); in the detail and status reads (`$contained`) that kind is treated as not
 * applying and the failure is only logged, as `ThemeAdapters` does (the stored failure record of "Failure isolation"
 * is not built yet for any contribution). [UNAPPROVED N366]
 */
final class PayerKinds
{
    /** @param ?Ledger $ledger read lazily: the kinds alone need no database */
    public function __construct(private ?Ledger $ledger = null)
    {
    }

    /** The participant's label: approved by the owner 2026-11-18 („Jelentkező” for a person everywhere). */
    private const PARTICIPANT_LABEL = 'Jelentkező';

    /**
     * The kinds that apply to the event, the participant first. `$contained` is for a read outside any
     * transaction: a throwing source is logged and its kind is treated as not applying.
     *
     * @return list<PayerKind>
     */
    public function of(int $eventId, bool $contained = false): array
    {
        $kinds = [new PayerKind(Payer::PARTICIPANT, self::PARTICIPANT_LABEL)];
        $input = new PayerKindInput($eventId);
        foreach (Contributions::current()->payerKindSources() as $source) {
            $applies = false;
            $ask = static function () use ($source, $input, &$applies): void {
                $applies = $source->appliesTo($input);
            };
            if ($contained) {
                // As `ThemeAdapters` asks each adapter: a source that throws is logged and its kind does not apply.
                Boundary::background('payer kind ' . $source->kind()->id, $ask);
            } else {
                $ask();
            }
            if ($applies) {
                $kinds[] = $source->kind();
            }
        }

        return $kinds;
    }

    /**
     * The payers of one registration, in the order the money tab shows them: the participant first, then the
     * event's other payer kinds that apply (with or without lines), then payers that have lines but no applying
     * kind, in the order the lines are shown. The label is null for a payer no kind declares. This is the one rule
     * for who is listed and who is accepted (`mayRecordFor()`): what the dialog shows is what the server accepts.
     * A plain read of the entries it is given. `$contained` is the detail read's (outside any transaction): a
     * throwing kind source is logged and skipped there, and propagates in a record use case. [UNAPPROVED N147]
     *
     * @param list<LedgerEntry> $entries the registration's lines (`Ledger::entriesOf()`)
     * @return array<string, ?string> payer ID → label
     */
    public function payersOf(RegistrationKey $registration, array $entries, bool $contained = false): array
    {
        $order = [];
        foreach ($this->of($registration->eventId, $contained) as $kind) {
            $order[$kind->id] = $kind->label;
        }
        foreach ($entries as $entry) {
            $order[$entry->payer] ??= $this->label($entry->payer);
        }

        return $order;
    }

    /**
     * Whether money may be recorded against the payer on the registration (a payment, a refund, a correction, a
     * Díj or a Kedvezmény): it is one of the registration's payers (`payersOf()`), so a payer whose kind no
     * longer applies, but has lines, can still be refunded, corrected, charged and discounted. A plain read.
     * [UNAPPROVED N147]
     */
    public function mayRecordFor(RegistrationKey $registration, string $payer): bool
    {
        $this->ledger ??= new Ledger();

        return array_key_exists($payer, $this->payersOf($registration, $this->ledger->entriesOf($registration)));
    }

    /** The label of a payer-kind ID, for an event or not: a kind that no longer applies still names its lines. */
    public function label(string $id): ?string
    {
        if ($id === Payer::PARTICIPANT) {
            return self::PARTICIPANT_LABEL;
        }
        foreach (Contributions::current()->payerKindSources() as $source) {
            if ($source->kind()->id === $id) {
                return $source->kind()->label;
            }
        }

        return null;
    }
}
