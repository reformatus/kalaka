<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

/**
 * What `Ledger::record()` returns: the line the registration now holds under the key, and whether this call
 * inserted it (`false` for a replay of the same line, which writes nothing).
 */
final readonly class RecordedLine
{
    public function __construct(public LedgerLine $line, public bool $inserted)
    {
    }
}
