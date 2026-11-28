<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

/**
 * One other payer's money table on a registration (the congregation's, for example): the payer's ID, its label
 * (the payer kind's own; the ID when no kind declares one) and the table, built by the same code as the
 * participant's. [UNAPPROVED N692]
 */
final readonly class OtherPayerMoney
{
    public function __construct(
        public string $payer,
        public string $label,
        public ParticipantMoney $money,
    ) {
    }
}
