<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Foundation\Api\Money\Money;

/**
 * What the status block needs to offer the registrant's own withdrawal: server facts as plain inputs, nothing
 * decided here. `canWithdraw` is the status read's `can.withdraw`; the page shows the button only on it. The
 * confirmation step shows while `confirming` (the page's own `?kalaka_withdraw=confirm` request), with the money
 * line from the preview's `paid` and `owedAfter` when `paid` is above zero. `refusal` is the code of a refused
 * post that came back, read once. [UNAPPROVED N765 to N779]
 *
 * PROTOTYPE: the route and `can.withdraw` exist now (#253); this
 * object is built by the walk-only spike plugin (the block render's offer, F6); `canWithdraw` and `refusal` are the
 * status read's `can.withdraw` and `withdrawRefusal`. `owedAfter` is the preview's signed value (below zero: a
 * surplus); `previewFailed` is set when a registered one's preview gave nothing.
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
        public bool $previewFailed = false,
    ) {
    }
}
