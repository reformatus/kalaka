<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Periodic;

use Kalaka\Registrations\Api\RegistrationKey;

/** A rule of work that falls due over time: it names the registrations that became due in a window and never writes. */
interface TimeRule
{
    /** A stable, unique identifier, for example `late-fee`: the key of a kept window and the name in the error log. */
    public function name(): string;

    /** @return list<RegistrationKey> */
    public function due(TimeRuleInput $input): array;
}
