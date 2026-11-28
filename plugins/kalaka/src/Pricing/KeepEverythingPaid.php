<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Pricing\Api\RetentionInput;
use Kalaka\Pricing\Api\RetentionRule;

/** Core's default retention rule: each payer's own net payments are kept, so nobody owes more or is overpaid. */
final class KeepEverythingPaid implements RetentionRule
{
    public const ID = 'everything_paid';

    public function id(): string
    {
        return self::ID;
    }

    public function kept(RetentionInput $input): array
    {
        return array_filter($input->paid, static fn ($amount): bool => $amount->isPositive());
    }
}
