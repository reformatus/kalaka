<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

/**
 * The payer kinds that apply to an event (money.md, "Ledger": "extensions register other payer kinds"), the
 * one source for who may pay a registration: the manual payment use cases check against it and the admin
 * detail read labels its payers with it. [UNAPPROVED Shapes]
 *
 * Core declares the participant. No typed contribution exists yet: `Contributions` gets its method together
 * with the first extension that declares a kind (the congregation share, S29), as its docblock says, and
 * this read then adds the kinds active for the event. Until then `$eventId` selects nothing.
 */
final class PayerKinds
{
    /** The participant's label: approved by the owner 2026-11-18 („Jelentkező” for a person everywhere). */
    private const PARTICIPANT_LABEL = 'Jelentkező';

    /**
     * The kinds that apply to the event, the participant first.
     *
     * @return list<PayerKind>
     */
    public function of(int $eventId): array
    {
        unset($eventId);

        return [new PayerKind(Payer::PARTICIPANT, self::PARTICIPANT_LABEL)];
    }
}
