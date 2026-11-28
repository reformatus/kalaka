<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

/** A declared payer kind: its ID (the `payer` of a ledger line) and its Hungarian label. [UNAPPROVED Shapes] */
final readonly class PayerKind
{
    public function __construct(public string $id, public string $label)
    {
    }
}
