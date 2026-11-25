<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

/**
 * What an event keeps when a registration leaves (money.md, "Withdrawal, removal and substitution"): the retention
 * line's names. The rules themselves are `RetentionRule`s, chosen per event in its money settings; the default is
 * to keep everything paid. Nothing is refunded either way. [UNAPPROVED N526]
 */
final class Retention
{
    /** The source key of the retention line, owed by a payer of a registration that left. */
    public const LINE = 'retention';

    /** The label of the retention line. [UNAPPROVED T505] */
    public const LABEL = 'Megtartott összeg';

    /** The rule an event has until it chooses one. */
    public const DEFAULT = 'everything_paid';
}
