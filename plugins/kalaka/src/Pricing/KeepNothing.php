<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Pricing\Api\RetentionInput;
use Kalaka\Pricing\Api\RetentionRule;

/** Core's other retention rule: nothing is kept, so everything paid shows as an overpayment. */
final class KeepNothing implements RetentionRule
{
    public const ID = 'nothing';

    public function id(): string
    {
        return self::ID;
    }

    public function kept(RetentionInput $input): array
    {
        return [];
    }
}
