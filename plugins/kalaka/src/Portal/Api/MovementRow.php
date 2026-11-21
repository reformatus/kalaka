<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Foundation\Api\Money\Money;

/**
 * One money movement of the participant, as the table lists it. The amount is signed by what it does to the paid
 * total: a payment is positive, a refund negative, a correction by its `direction`. `direction` is set on a
 * correction only; a correction has no method; `valueDay` is a `Y-m-d` day in the site time zone.
 */
final readonly class MovementRow
{
    public function __construct(
        public MovementType $type,
        public ?MovementDirection $direction,
        public ?string $methodLabel,
        public ?string $note,
        public string $valueDay,
        public Money $amount,
    ) {
    }
}
