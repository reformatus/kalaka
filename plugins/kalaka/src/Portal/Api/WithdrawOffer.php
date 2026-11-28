<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Foundation\Api\Money\Money;

/**
 * What the status block needs to offer the registrant's own withdrawal: server facts as plain inputs, nothing
 * decided here. `canWithdraw` is the status read's `can.withdraw`; the page shows the button only on it. The
 * confirmation shows in a WordPress Modal opened by script from the button (R370), or, without script, as a section
 * on the page's own `?kalaka_withdraw=confirm` request (`confirming`). Either way its money line comes from the
 * preview's `paid` and `owedAfter`. `refusal` is the code of a refused post that came back, read once.
 * [UNAPPROVED N765 to N779, N1333 to N1349]
 *
 * Built by `ParticipantBlocks` for the block and its shortcode: `canWithdraw` and `refusal` are the status
 * read's `can.withdraw` and `withdrawRefusal`, the amounts the real preview's, taken while the button shows.
 * `owedAfter` is the preview's signed value (below zero: a surplus); `previewFailed` is set when a registered one's
 * preview gave nothing; `waitlisted` is the status read's (a waitlisted registration with nothing paid shows no
 * money line).
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
        public bool $waitlisted = false,
    ) {
    }
}
