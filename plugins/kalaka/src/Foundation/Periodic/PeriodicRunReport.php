<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Periodic;

/** What a periodic run did: the registrations it evaluated, and those whose evaluation failed and were flagged. */
final readonly class PeriodicRunReport
{
    public function __construct(public int $evaluated, public int $failed)
    {
    }
}
