<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Payments;

use Kalaka\Foundation\Api\Actor\SecretLinkActor;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Database\NamedLock;
use Kalaka\Payments\Api\CardPaymentInFlight;
use Kalaka\Payments\Api\CardPaymentUnavailable;
use Kalaka\Payments\Api\CardPayments;
use Kalaka\Payments\Api\NoPaymentProvider;
use Kalaka\Payments\Api\OpenPayment;
use Kalaka\Payments\Api\OpenPaymentState;
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

    private ?\mysqli $other = null;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->provider = new FakeProvider();
        $this->contribute($this->provider);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        $this->other?->close();
        $this->other = null;

        parent::tear_down();
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

    public function testNoProviderMethodIsCalledInsideTheRun(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '5000');

        (new CardPayments())->start($key);

        // Every method core calls: asked outside the transaction, never inside the use case.
        self::assertSame(['latestOf' => false, 'accepts' => false, 'payUrl' => false], $this->provider->insideARun);
        self::assertFalse($this->provider->ranInsideARun);
    }

    public function testTheProviderIsAskedWhileOnlyThisStartHoldsTheRegistrationsLock(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '5000');
        $name = (new NamedLock('card_' . $key->eventId . '_12a3'))->scoped();
        $free = null;
        $this->provider->whilePaying = function () use ($name, &$free): void {
            $free = $this->otherConnection()->query("SELECT IS_FREE_LOCK('" . $name . "') AS free")?->fetch_row()[0];
        };

        (new CardPayments())->start($key);

        self::assertSame('0', $free, 'The lock is held while the provider makes the order.');
        self::assertSame(
            '1',
            $this->otherConnection()->query("SELECT IS_FREE_LOCK('" . $name . "') AS free")?->fetch_row()[0],
            'And free again after.'
        );
    }

    public function testAStartThatCannotGetTheLockIsRefusedAsInFlightAndCallsNoProvider(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '5000');
        $name = (new NamedLock('card_' . $key->eventId . '_12a3'))->scoped();
        $other = $this->otherConnection();
        $other->query("SELECT GET_LOCK('" . $name . "', 0)");

        try {
            (new CardPayments(waitMs: 0))->start($key);
            self::fail('Nothing refused it.');
        } catch (CardPaymentInFlight $e) {
            self::assertSame('kalaka_card_payment_in_flight', $e->errorCode());
            self::assertSame([], $this->provider->requests);
            self::assertSame([], $this->provider->insideARun);
        } finally {
            $other->query("SELECT RELEASE_LOCK('" . $name . "')");
        }
    }

    public function testARequestInFlightAtTheProviderIsRefusedAndAPendingOneIsNot(): void
    {
        $key = $this->stored('12A3');
        $this->charge($key, '5000');
        $since = new \DateTimeImmutable('2026-11-30 09:30:00');

        $this->provider->latest = new OpenPayment(OpenPaymentState::Pending, $since);
        (new CardPayments())->start($key);
        foreach ([OpenPaymentState::Processing, OpenPaymentState::Paid] as $state) {
            $this->provider->latest = new OpenPayment($state, $since);
            try {
                (new CardPayments())->start($key);
                self::fail('Nothing refused it.');
            } catch (CardPaymentInFlight $e) {
                self::assertSame('kalaka_card_payment_in_flight', $e->errorCode());
            }
        }
        $this->provider->latest = new OpenPayment(OpenPaymentState::Failed, $since);
        (new CardPayments())->start($key);
        self::assertCount(2, $this->provider->requests);
    }

    private function otherConnection(): \mysqli
    {
        if ($this->other === null) {
            $this->other = new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        }

        return $this->other;
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
            new StartCardPayment($key, null, home_url('/'), status: $read),
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
            new StartCardPayment($mine, null, home_url('/')),
            [],
            new SecretLinkActor($other)
        );
    }
}
