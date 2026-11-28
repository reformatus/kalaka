<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

/** What the start use case decided: the request to give the provider and where the registrant returns to. */
final readonly class StartedCardPayment
{
    public function __construct(public PaymentRequest $request, public string $returnUrl)
    {
    }
}
