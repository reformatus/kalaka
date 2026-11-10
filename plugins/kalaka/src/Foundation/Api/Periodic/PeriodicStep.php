<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Periodic;

/**
 * Work a module adds to the end of every periodic run, after the sweep and the retries of flagged
 * registrations, such as the mail sender (foundation.md, "Deferred work"). A module contributes one with
 * `Contributions::periodicStep()`. Each step runs behind the error boundary: whatever escapes it is
 * logged unless a run recorded it, and the periodic run goes on with the next step.
 */
interface PeriodicStep
{
    /** `[a-z][a-z0-9_]*`; names the step in the error log. */
    public function name(): string;

    public function run(): void;
}
