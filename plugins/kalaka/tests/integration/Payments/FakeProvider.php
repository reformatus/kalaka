<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Payments;

use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Payments\Api\OpenPayment;
use Kalaka\Payments\Api\AbstractPaymentProvider;
use Kalaka\Payments\Api\PaymentProviderInput;
use Kalaka\Payments\Api\PaymentRequest;

/** A payment provider for the tests: it records what it was asked and answers a fixed address. */
final class FakeProvider extends AbstractPaymentProvider
{
    /** @var list<PaymentRequest> */
    public array $requests = [];

    /** @var list<string> */
    public array $returnUrls = [];

    /** Whether a run was open while `payUrl()` ran. */
    public ?bool $ranInsideARun = null;

    /** @var array<string, bool> by method: whether a run was open while the method ran (each method called) */
    public array $insideARun = [];

    /** What `latestOf()` answers. */
    public ?OpenPayment $latest = null;

    /** @var ?callable(): void run inside `payUrl()` */
    public $whilePaying = null;

    public function __construct(private readonly string $id = 'fake', public bool $accepts = true)
    {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function accepts(PaymentProviderInput $input): bool
    {
        $this->insideARun['accepts'] = Runner::activeRun() !== null;

        return $this->accepts;
    }

    public function payUrl(PaymentProviderInput $input): string
    {
        $this->ranInsideARun = Runner::activeRun() !== null;
        $this->insideARun['payUrl'] = $this->ranInsideARun;
        if ($this->whilePaying !== null) {
            ($this->whilePaying)();
        }
        $this->requests[] = $input->request();
        $this->returnUrls[] = $input->returnUrl();

        return 'https://pay.example.test/' . count($this->requests);
    }

    public function latestOf(PaymentProviderInput $input): ?OpenPayment
    {
        $this->insideARun['latestOf'] = Runner::activeRun() !== null;

        return $this->latest;
    }
}
