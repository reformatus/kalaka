<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

/**
 * What a fee component throws when it cannot say what a registration owes and a person can act on why, e.g.
 * the price ladder when the chosen tier does not apply. The evaluation refuses the use case with the text for
 * the actor's side (`FeeRefused`, a `RuleViolation`), naming the rule `money/<component id>`, so the
 * periodic run flags the registration as any failing rule. [UNAPPROVED, #200 N11]
 */
final class FeeRefusal extends \RuntimeException
{
    /**
     * @param string $adminText what staff see (an admission, a restore, a staff write)
     * @param ?string $portalText what the registrant sees (their own submit); null: the generic failure text,
     *     for a refusal the registrant's form cannot reach
     */
    public function __construct(
        public readonly string $adminText,
        public readonly ?string $portalText = null,
        string $reason = '',
    ) {
        parent::__construct($reason);
    }
}
