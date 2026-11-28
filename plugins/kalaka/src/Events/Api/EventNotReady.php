<?php

declare(strict_types=1);

namespace Kalaka\Events\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * An event that cannot be published yet: the fields it still lacks are `missing`, by key (`name`, `capacity`,
 * `registration_opens_at`, `registration_closes_at`, `starts_at`, `ends_at`). The text is the rule violation's;
 * the keys are for the operator's console (`wp kalaka event publish`) and are not sent to REST.
 */
final class EventNotReady extends RuleViolation
{
    /** @param list<string> $missing */
    public function __construct(public readonly array $missing)
    {
        parent::__construct();
    }

    public function errorCode(): string
    {
        return 'kalaka_event_not_ready';
    }
}
