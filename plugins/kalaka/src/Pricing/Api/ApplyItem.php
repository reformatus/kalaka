<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Registrations\Api\RegistrationKey;

/**
 * One registration in a money settings apply's report: done, or failed with the error. A failed one can be
 * retried (`MoneySettings::retry()`) or skipped; either way it converges on its next evaluation, since the
 * settings are already saved.
 */
final readonly class ApplyItem
{
    public function __construct(public RegistrationKey $registration, public ?\Throwable $failure = null)
    {
    }

    public function isDone(): bool
    {
        return $this->failure === null;
    }
}
