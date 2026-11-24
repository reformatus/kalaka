<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The payer kinds that apply to an event (money.md, "Ledger": "extensions register other payer kinds"), the
 * one source for who may pay a registration: the manual payment use cases check against it and the admin
 * detail read labels its payers with it. [UNAPPROVED Shapes]
 *
 * Core declares the participant; an extension declares another through a `PayerKindSource` (the
 * congregation share, S29), and this read adds the kinds that apply to the event.
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
     * The kinds that apply to the event, the participant first.
     *
     * @return list<PayerKind>
     */
    public function of(int $eventId): array
    {
        return [
            new PayerKind(Payer::PARTICIPANT, self::PARTICIPANT_LABEL),
            ...Contributions::current()->payerKindsFor($eventId),
        ];
    }

    /**
     * The payers of one registration, in the order the money tab shows them: the participant first, then the
     * event's other payer kinds that apply (with or without lines), then payers that have lines but no applying
     * kind, in the order the lines are shown. The label is null for a payer no kind declares. This is the one rule
     * for who is listed and who is accepted (`mayRecordFor()`): what the dialog shows is what the server accepts.
     * A plain read of the entries it is given. [UNAPPROVED N147]
     *
     * @param list<LedgerEntry> $entries the registration's lines (`Ledger::entriesOf()`)
     * @return array<string, ?string> payer ID → label
     */
    public function payersOf(RegistrationKey $registration, array $entries): array
    {
        $order = [];
        foreach ($this->of($registration->eventId) as $kind) {
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
        $declared = [
            new PayerKind(Payer::PARTICIPANT, self::PARTICIPANT_LABEL),
            ...Contributions::current()->payerKinds(),
        ];
        foreach ($declared as $kind) {
            if ($kind->id === $id) {
                return $kind->label;
            }
        }

        return null;
    }
}
