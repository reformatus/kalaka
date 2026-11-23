<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

/**
 * One payment or refund line as a mail reads it: the movement as the table lists it, and the stable ID of its
 * method for a mail's condition (`bank_transfer`, `cash`, `card_terminal` or `online`; null for a movement with
 * no method).
 */
final readonly class MailMovement
{
    public function __construct(public MovementRow $row, public ?string $methodId)
    {
    }
}
