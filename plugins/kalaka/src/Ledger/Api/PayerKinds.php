<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

use Kalaka\Foundation\Api\Module\Contributions;

/**
 * The payer kinds that apply to an event (money.md, "Ledger": "extensions register other payer kinds"), the
 * one source for who may pay a registration: the manual payment use cases check against it and the admin
 * detail read labels its payers with it. [UNAPPROVED Shapes]
 *
 * Core declares the participant; an extension declares another through `Contributions::payerKind()` (the
 * congregation share, S29). A declared kind applies to every event while its module is active, so `$eventId`
 * selects nothing.
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

        return [new PayerKind(Payer::PARTICIPANT, self::PARTICIPANT_LABEL), ...Contributions::current()->payerKinds()];
    }

    /** The label of a payer-kind ID; null when nothing declares it. */
    public function label(string $id): ?string
    {
        foreach ($this->of(0) as $kind) {
            if ($kind->id === $id) {
                return $kind->label;
            }
        }

        return null;
    }
}
