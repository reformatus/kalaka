<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

use Kalaka\Ledger\Api\LineKind;
use Kalaka\Ledger\Api\Payer;

/**
 * Which ledger lines the "payment recorded" mail announces (mail.md, "Five kinds of mail"): the participant's own
 * money moving, a payment or a refund. It is the mail's ruling, not a ledger fact: a correction is a payment fact
 * of the participant too, and the mail leaves it out (so it is not Portal's "movement", which includes
 * corrections). The one rule, which `ActionMails::lineRecorded()` (the queue) and `Portal\Api\MailMoney` (the read
 * of the line at send time) both call.
 */
final class PaymentRecordedLines
{
    private function __construct()
    {
    }

    /** Whether the mail announces a line: a payment fact of the participant payer that is not a correction. */
    public static function announces(LineKind $kind, string $payer, bool $correction): bool
    {
        return $kind->isPaymentFact() && !$correction && $payer === Payer::PARTICIPANT;
    }
}
