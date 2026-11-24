<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Payments;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Payments\Api\OpenPayment;
use Kalaka\Payments\Api\PaymentProvider;
use Kalaka\Payments\Api\PaymentRequest;
use Kalaka\Registrations\Api\RegistrationKey;

/** A payment provider for the tests: it records what it was asked and answers a fixed address. */
final class FakeProvider implements PaymentProvider
{
    /** @var list<PaymentRequest> */
    public array $requests = [];

    /** @var list<string> */
    public array $returnUrls = [];

    /** Whether a run was open while `payUrl()` ran. */
    public ?bool $ranInsideARun = null;

    public function __construct(private readonly string $id = 'fake', public bool $accepts = true)
    {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function accepts(Currency $currency): bool
    {
        return $this->accepts;
    }

    public function payUrl(PaymentRequest $request, string $returnUrl): string
    {
        $this->ranInsideARun = Runner::activeRun() !== null;
        $this->requests[] = $request;
        $this->returnUrls[] = $returnUrl;

        return 'https://pay.example.test/' . count($this->requests);
    }

    public function latestOf(RegistrationKey $key): ?OpenPayment
    {
        return null;
    }
}
