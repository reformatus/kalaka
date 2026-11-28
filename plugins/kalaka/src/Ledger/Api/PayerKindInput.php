<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

/** What a payer-kind source gets (modules-and-extensions.md, "Contracts": one immutable input per point): the event. */
final readonly class PayerKindInput
{
    public function __construct(public int $eventId)
    {
    }
}
