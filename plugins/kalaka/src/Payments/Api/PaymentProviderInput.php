<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * What a payment provider's operations are given (modules-and-extensions.md, "Contracts"): one immutable value, so
 * a new field breaks no provider. Built with a named constructor: `forStart()` for the pay URL and `forLookup()`
 * for the state of a registration's request; `accepts()` takes either.
 */
final readonly class PaymentProviderInput
{
    /**
     * @param list<RegistrationKey> $registrations at least one
     */
    private function __construct(
        public Currency $currency,
        public array $registrations,
        public ?PaymentRequest $request,
        public ?string $returnUrl,
    ) {
    }

    /** A start: the request (its lines, currency and email) and where the payer comes back to. */
    public static function forStart(PaymentRequest $request, string $returnUrl): self
    {
        $registrations = [];
        foreach ($request->lines as $line) {
            $key = $line->registration;
            $registrations[$key->eventId . ':' . strtolower($key->code)] = $key;
        }

        return new self($request->currency, array_values($registrations), $request, $returnUrl);
    }

    /** A look at one registration's latest request, or whether the provider collects in the currency. */
    public static function forLookup(RegistrationKey $key, Currency $currency): self
    {
        return new self($currency, [$key], null, null);
    }

    /** The first registration of the input; the only one of a lookup. */
    public function registration(): RegistrationKey
    {
        return $this->registrations[0];
    }

    /** @throws \LogicException when the input is a lookup, which has no request */
    public function request(): PaymentRequest
    {
        return $this->request ?? throw new \LogicException('This input is not a start: it has no request.');
    }

    /** @throws \LogicException when the input is a lookup, which has no return URL */
    public function returnUrl(): string
    {
        return $this->returnUrl ?? throw new \LogicException('This input is not a start: it has no return URL.');
    }
}
