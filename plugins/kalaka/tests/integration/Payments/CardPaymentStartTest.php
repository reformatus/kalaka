<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Payments;

use Kalaka\Foundation\Api\Actor\SecretLinkActor;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Payments\Api\CardPaymentUnavailable;
use Kalaka\Payments\Api\CardPayments;
use Kalaka\Payments\Api\NoPaymentProvider;
use Kalaka\Payments\Api\PaymentProviders;
use Kalaka\Payments\StartCardPayment;
use Kalaka\Portal\Api\StatusRead;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\Requests;
use Kalaka\Tests\Integration\Portal\PortalTestCase;
use Kalaka\Tests\Integration\Registrations\TestRequestSource;

/** Starting a card payment: what is payable, the refusals, the request the provider is given and when. */
final class CardPaymentStartTest extends PortalTestCase
{
    private FakeProvider $provider;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->provider = new FakeProvider();
        $this->contribute($this->provider);
    }

    private function contribute(FakeProvider ...$providers): void
    {
        $contributions = new Contributions();
        foreach ($providers as $provider) {
            $contributions->paymentProvider($provider);
        }
        Contributions::record($contributions);
    }

    public function testItGivesTheProviderOneLineForTheParticipantsDueRoundedUp(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '12000.40');

        $url = (new CardPayments())->start($key);

        self::assertSame('https://pay.example.test/1', $url);
        self::assertCount(1, $this->provider->requests);
        $request = $this->provider->requests[0];
        self::assertCount(1, $request->lines);
        self::assertSame('12A3', $request->lines[0]->registration->code);
        self::assertSame('participant', $request->lines[0]->payer);
        self::assertSame('12001', rtrim(rtrim($request->lines[0]->amount->amount(), '0'), '.'));
        self::assertSame('Nyári tábor: befizetés (12A3)', $request->lines[0]->label);
        self::assertNull($request->email);
    }

    public function testTheProviderIsCalledAfterTheRunNotInsideIt(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '5000');

        (new CardPayments())->start($key);

        self::assertFalse($this->provider->ranInsideARun);
    }

    public function testOnlyASameSiteReturnAddressIsUsed(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '5000');
        $payments = new CardPayments();

        $payments->start($key);
        $payments->start($key, home_url('/jelentkezes/'));
        $payments->start($key, 'https://elsewhere.example.net/steal');

        self::assertSame(home_url('/jelentkezes/'), $this->provider->returnUrls[1]);
        self::assertSame($this->provider->returnUrls[0], $this->provider->returnUrls[2]);
        self::assertSame(home_url('/'), $this->provider->returnUrls[0]);
    }

    /** @return array<string, array{callable(self): RegistrationKey}> */
    public static function notPayable(): array
    {
        return [
            'waitlisted' => [static function (self $t): RegistrationKey {
                $key = $t->stored('12A3', RegistrationStatus::Waitlisted);
                $t->charge($key, '5000');

                return $key;
            }],
            'withdrawn' => [static function (self $t): RegistrationKey {
                $key = $t->stored('12A3', RegistrationStatus::Withdrawn);
                $t->charge($key, '5000');

                return $key;
            }],
            'the event is not published' => [static function (self $t): RegistrationKey {
                $key = $t->stored('12A3');
                $t->charge($key, '5000');
                $t->event(['state' => 'draft']);

                return $key;
            }],
            'nothing is due' => [static fn (self $t): RegistrationKey => $t->stored('12A3')],
            'it is paid' => [static function (self $t): RegistrationKey {
                $key = $t->stored('12A3');
                $t->charge($key, '5000');
                $t->pay($key, '5000');

                return $key;
            }],
        ];
    }

    /**
     * @dataProvider notPayable
     * @param callable(self): RegistrationKey $setUp
     */
    public function testWhatIsNotPayableIsRefusedWithItsCodeAndCallsNoProvider(callable $setUp): void
    {
        $key = $setUp($this);
        try {
            (new CardPayments())->start($key);
            self::fail('Nothing refused it.');
        } catch (CardPaymentUnavailable $e) {
            self::assertSame('kalaka_card_payment_unavailable', $e->errorCode());
            self::assertSame([], $this->provider->requests);
        }
    }

    public function testARegistrationThatAwaitsAnAnswerIsRefused(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '5000');
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_field_values', [
            'event_id' => $key->eventId,
            'code' => $key->code,
            'field_key' => 'support',
            'version' => 1,
            'value' => 'church',
        ]);
        $read = new StatusRead(registrations: new Registrations(requests: new Requests([new TestRequestSource()])));

        $this->expectException(CardPaymentUnavailable::class);
        $this->runner->run(
            new StartCardPayment($key, $this->provider, null, home_url('/'), status: $read),
            [],
            new SecretLinkActor($key)
        );
    }

    public function testWithoutAProviderItIsRefusedWithItsCode(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '5000');
        Contributions::record(new Contributions());

        try {
            (new CardPayments())->start($key);
            self::fail('Nothing refused it.');
        } catch (NoPaymentProvider $e) {
            self::assertSame('kalaka_no_payment_provider', $e->errorCode());
        }
    }

    public function testAProviderThatDoesNotAcceptTheCurrencyIsRefused(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '5000');
        $this->provider->accepts = false;

        $this->expectException(NoPaymentProvider::class);
        (new CardPayments())->start($key);
    }

    public function testTheFirstProviderIsTheActiveOne(): void
    {
        $second = new FakeProvider('second');
        $this->contribute($this->provider, $second);

        self::assertSame($this->provider, (new PaymentProviders())->active());
    }

    public function testASecondProviderWithTheSameIdFailsItsContribution(): void
    {
        $contributions = new Contributions();
        $contributions->paymentProvider(new FakeProvider('same'));

        $this->expectException(\InvalidArgumentException::class);
        $contributions->paymentProvider(new FakeProvider('same'));
    }

    public function testAnotherRegistrationsKeyIsNotThePortalActors(): void
    {
        $mine = $this->stored('12A3');
        $this->charge($mine, '5000');
        $other = new RegistrationKey(self::EVENT, '00A5');

        $this->expectException(\Kalaka\Foundation\Api\Error\PermissionDenied::class);
        $this->runner->run(
            new StartCardPayment($mine, $this->provider, null, home_url('/')),
            [],
            new SecretLinkActor($other)
        );
    }
}
