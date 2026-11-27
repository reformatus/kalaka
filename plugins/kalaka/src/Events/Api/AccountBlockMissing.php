<?php

declare(strict_types=1);

namespace Kalaka\Events\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * An account mode that a published registration form of the event cannot take: the IDs of the forms without the
 * account block are `forms`. The text is the rule violation's; the IDs are for the operator's console
 * (`wp kalaka event update`) and are not sent to REST.
 */
final class AccountBlockMissing extends RuleViolation
{
    /** @param list<int> $forms */
    public function __construct(public readonly array $forms)
    {
        parent::__construct();
    }

    public function errorCode(): string
    {
        return 'kalaka_account_block_missing';
    }
}
