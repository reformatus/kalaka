<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Payments;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Payments\Api\AbstractPaymentProvider;
use Kalaka\Payments\Api\PaymentProviderInput;
use Kalaka\Payments\Api\PaymentRequest;
use Kalaka\Payments\Api\RequestLine;
use Kalaka\Registrations\Api\RegistrationKey;
use PHPUnit\Framework\TestCase;

/** What a payment provider is given, and what its base answers by default. */
final class PaymentProviderInputTest extends TestCase
{
    public function testAStartCarriesTheRequestTheReturnUrlAndEachRegistrationOnce(): void
    {
        $huf = Currency::HUF;
        $a = new RegistrationKey(12, '00A0');
        $b = new RegistrationKey(12, '12A37');
        $line = static fn(RegistrationKey $k) => new RequestLine($k, 'participant', Money::of(1000, $huf), 'Díj');
        $request = new PaymentRequest([$line($a), $line($b), $line($a)], $huf, 'a@example.test');

        $input = PaymentProviderInput::forStart($request, 'https://example.test/back');

        self::assertSame($request, $input->request());
        self::assertSame('https://example.test/back', $input->returnUrl());
        self::assertSame([$a, $b], $input->registrations);
        self::assertSame($a, $input->registration());
        self::assertSame($huf, $input->currency);
    }

    public function testALookupHasNoRequestAndNoReturnUrl(): void
    {
        $huf = Currency::HUF;
        $key = new RegistrationKey(12, '00A0');
        $input = PaymentProviderInput::forLookup($key, $huf);

        self::assertSame([$key], $input->registrations);
        self::assertSame($huf, $input->currency);
        $this->expectException(\LogicException::class);
        $input->request();
    }

    public function testTheBaseAnswersNoRequestByDefault(): void
    {
        $provider = new class extends AbstractPaymentProvider {
            public function id(): string
            {
                return 'bare';
            }

            public function accepts(PaymentProviderInput $input): bool
            {
                return true;
            }

            public function payUrl(PaymentProviderInput $input): string
            {
                return 'https://pay.example.test/';
            }
        };

        $huf = Currency::HUF;
        self::assertNull($provider->latestOf(PaymentProviderInput::forLookup(new RegistrationKey(12, '00A0'), $huf)));
    }
}
