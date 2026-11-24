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
     * Whether money may be recorded against the payer on the registration with a payment, a refund or a
     * correction: the payer's kind applies to the event, or the payer already has lines on this registration
     * (the same rule the admin detail read uses to keep showing it, so what the dialog shows is what the server
     * accepts). A kind that no longer applies still has its lines refunded and corrected. A plain read.
     * [UNAPPROVED N147]
     */
    public function mayRecordFor(RegistrationKey $registration, string $payer): bool
    {
        foreach ($this->of($registration->eventId) as $kind) {
            if ($kind->id === $payer) {
                return true;
            }
        }
        $this->ledger ??= new Ledger();
        foreach ($this->ledger->entriesOf($registration) as $entry) {
            if ($entry->payer === $payer) {
                return true;
            }
        }

        return false;
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
