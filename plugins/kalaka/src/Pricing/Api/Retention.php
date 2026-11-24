<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Money\Money;

/**
 * What an event keeps when a registration leaves (money.md, "Withdrawal, removal and substitution"): per payer,
 * never more than that payer paid. Core offers "keep nothing" and "keep everything paid"; the deposit extension
 * adds "keep up to the deposit" later. The rule is chosen per event in its money settings; the default is to keep
 * everything paid. Nothing is refunded either way. [UNAPPROVED N526]
 */
enum Retention: string
{
    case Nothing = 'nothing';
    case EverythingPaid = 'everything_paid';

    /** The source key of the retention line, owed by a payer of a registration that left. */
    public const LINE = 'retention';

    /** The label of the retention line. [UNAPPROVED T505] */
    public const LABEL = 'Megtartott összeg';

    public static function default(): self
    {
        return self::EverythingPaid;
    }

    /** What is kept of a payer's net payments (`$paid`, zero or more): all of it, or nothing. */
    public function kept(Money $paid): Money
    {
        return $this === self::EverythingPaid && $paid->isPositive() ? $paid : Money::zero($paid->currency());
    }
}
