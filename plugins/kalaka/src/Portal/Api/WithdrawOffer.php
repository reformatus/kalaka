<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Foundation\Api\Money\Money;

/**
 * What the status block needs to offer the registrant's own withdrawal: server facts as plain inputs, nothing
 * decided here. `canWithdraw` is the status read's `can.withdraw`; the page shows the button only on it. The
 * confirmation step shows while `confirming` (the page's own `?kalaka_withdraw=confirm` request), with the money
 * line from the preview's `paid` and `owedAfter` when `paid` is above zero. `refusal` is the code of a refused
 * post that came back, read once. [UNAPPROVED N765 to N777]
 *
 * PROTOTYPE: until the status read carries `can.withdraw` and the route exists (Backend's PR on #247), this
 * object is built by the walk-only spike plugin.
 */
final readonly class WithdrawOffer
{
    public function __construct(
        public bool $canWithdraw,
        public string $actionUrl,
        public string $returnUrl,
        public string $code,
        public string $buttonClass = '',
        public ?string $errorColour = null,
        public bool $confirming = false,
        public ?string $refusal = null,
        public ?Money $paid = null,
        public ?Money $owedAfter = null,
    ) {
    }
}
