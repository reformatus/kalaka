<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Money\Money;

/**
 * The answer to a request, as the answer act gives it: an offer is approved or refused; aid is an award, an
 * amount above zero, and "no" is an award of 0 (B2), so `amount` is null for an offer, positive for a yes to
 * aid and zero for a no to aid. `note` is optional free text.
 */
final readonly class Answer
{
    public function __construct(public bool $approved, public ?Money $amount = null, public ?string $note = null)
    {
    }
}
