<?php

declare(strict_types=1);

namespace Kalaka\Support\Share;

use Kalaka\Ledger\Api\PayerKind;
use Kalaka\Ledger\Api\PayerKindInput;
use Kalaka\Ledger\Api\PayerKindSource;
use Kalaka\Support\Api\Share;

/**
 * The congregation as a payer kind (money.md, "Congregation share"): it applies to the events whose share is on.
 * It reads that one setting and nothing else. [UNAPPROVED N147, T65]
 */
final class SharePayerKind implements PayerKindSource
{
    /** The label of the kind. [UNAPPROVED T65] */
    public const LABEL = 'Gyülekezet';

    public function __construct(private readonly Share $share = new Share())
    {
    }

    public function kind(): PayerKind
    {
        return new PayerKind(ShareComponent::CONGREGATION, self::LABEL);
    }

    public function appliesTo(PayerKindInput $input): bool
    {
        return $this->share->isOn($input->eventId);
    }
}
