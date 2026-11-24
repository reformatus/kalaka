<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Payments;

use Kalaka\Forms\Api\ElementRole;
use Kalaka\Forms\Api\ThemeAdaptation;
use Kalaka\Forms\Api\ThemeStyle;
use Kalaka\Forms\Engine\ThemeAdapters;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Payments\Api\OpenPayment;
use Kalaka\Payments\Api\OpenPaymentState;
use Kalaka\Portal\Api\StatusRead;
use Kalaka\Portal\StatusMarkup;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Forms\TestThemeAdapter;
use Kalaka\Tests\Integration\Portal\PortalTestCase;

/** The status read's card state, the route that starts a card payment and the bare link to it. */
final class CardPaymentPortalTest extends PortalTestCase
{
    private FakeProvider $provider;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->provider = new FakeProvider();
        $this->contribute($this->provider);
        $GLOBALS['wp_rest_server'] = null;
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        $GLOBALS['wp_rest_server'] = null;

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

    private function owing(string $amount = '5000'): RegistrationKey
    {
        $key = $this->stored('12A3');
        $this->charge($key, $amount);
        $this->activate($key);

        return $key;
    }

    public function testItCanPayByCardWhenSomethingIsPayableAndAProviderAcceptsTheCurrency(): void
    {
        $this->owing();

        $view = (new StatusRead())->forActive();

        self::assertTrue($view?->can->payCard);
        self::assertNull($view->cardPayment);
    }

    public function testItCannotWithoutAProviderOrWhenItDoesNotAcceptOrNothingIsDue(): void
    {
        $key = $this->owing();
        $this->provider->accepts = false;
        self::assertFalse((new StatusRead())->forActive()?->can->payCard);

        $this->provider->accepts = true;
        Contributions::record(new Contributions());
        self::assertFalse((new StatusRead())->forActive()?->can->payCard);

        $this->contribute($this->provider);
        $this->pay($key, '5000');
        self::assertFalse((new StatusRead())->forActive()?->can->payCard);
    }

    public function testItCannotWhileTheEventIsNotPublishedOrTheRegistrationIsNotRegistered(): void
    {
        $this->owing();
        $this->event(['state' => 'draft']);
        self::assertFalse((new StatusRead())->forActive()?->can->payCard);

        $this->event();
        $waiting = $this->stored('00A5', \Kalaka\Registrations\Api\RegistrationStatus::Waitlisted);
        $this->activate($waiting);
        self::assertFalse((new StatusRead())->forActive()?->can->payCard);
    }

    public function testTheLatestRequestsStateAndTimeComeFromTheProvider(): void
    {
        $this->owing();
        $since = new \DateTimeImmutable('2026-11-30 07:00:00', new \DateTimeZone('UTC'));
        $this->provider->latest = new OpenPayment(OpenPaymentState::Failed, $since);

        $card = (new StatusRead())->forActive()?->cardPayment;

        self::assertSame(OpenPaymentState::Failed, $card?->state);
        self::assertEquals($since, $card?->since);
    }

    public function testAProviderThatFailsToReadNeverBreaksTheStatus(): void
    {
        $this->owing();
        $this->provider->failsToRead = true;

        $view = (new StatusRead())->forActive();

        self::assertNotNull($view);
        self::assertFalse($view->can->payCard);
        self::assertNull($view->cardPayment);
    }

    public function testTheBareLinkShowsOnlyWhileItCanBePaidByCard(): void
    {
        $key = $this->owing();

        $html = $this->statusBlock();
        self::assertStringContainsString('Fizetés bankkártyával', $html);
        self::assertStringContainsString(rest_url('kalaka-portal/v1/card-payment'), $html);

        $this->pay($key, '5000');
        self::assertStringNotContainsString('Fizetés bankkártyával', $this->statusBlock());
    }

    private function post(string $contentType, string $body): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', '/kalaka-portal/v1/card-payment');
        $request->set_header('Content-Type', $contentType);
        $request->set_body($body);
        if ($contentType === 'application/json') {
            $request->set_body_params((array) json_decode($body, true));
        } else {
            parse_str($body, $params);
            $request->set_body_params($params);
        }

        return rest_do_request($request);
    }

    public function testAJsonPostGivesTheUrl(): void
    {
        $this->owing();

        $response = $this->post('application/json', (string) wp_json_encode(['return_url' => home_url('/vissza/')]));

        self::assertSame(200, $response->get_status());
        self::assertSame(['url' => 'https://pay.example.test/1'], $response->get_data());
        self::assertSame(home_url('/vissza/'), $this->provider->returnUrls[0]);
        self::assertStringContainsString('no-store', (string) $response->get_headers()['Cache-Control']);
    }

    public function testAFormPostIsRedirectedWith303(): void
    {
        $this->owing();

        $response = $this->post('application/x-www-form-urlencoded', 'return_url=' . rawurlencode(home_url('/x/')));

        self::assertSame(303, $response->get_status());
        self::assertSame('https://pay.example.test/1', $response->get_headers()['Location']);
    }

    public function testAReturnAddressOnAnotherSiteIsNotUsed(): void
    {
        $this->owing();

        $this->post('application/json', (string) wp_json_encode(['return_url' => 'https://elsewhere.example.net/']));

        self::assertSame(home_url('/'), $this->provider->returnUrls[0]);
    }

    public function testWithoutAnActiveRegistrationItIsRefusedWithItsCode(): void
    {
        $this->activate(null);

        $response = $this->post('application/json', '{}');

        self::assertSame(422, $response->get_status());
        self::assertSame('kalaka_card_payment_unavailable', $response->get_data()['code'] ?? null);
        self::assertSame([], $this->provider->requests);
    }

    public function testWhatIsNotPayableIsRefusedWithItsCodeByTheRoute(): void
    {
        $key = $this->stored('12A3');
        $this->activate($key);

        $response = $this->post('application/json', '{}');

        self::assertSame(422, $response->get_status());
        self::assertSame('kalaka_card_payment_unavailable', $response->get_data()['code'] ?? null);
    }

    public function testWithoutAProviderTheRouteGivesItsCode(): void
    {
        $this->owing();
        Contributions::record(new Contributions());

        $response = $this->post('application/json', '{}');

        self::assertSame('kalaka_no_payment_provider', $response->get_data()['code'] ?? null);
    }

    /** @return array<string, array{OpenPaymentState, bool}> */
    public static function statesAndWhetherItCanPay(): array
    {
        return [
            'pending: the next start reuses the order' => [OpenPaymentState::Pending, true],
            'failed: paid again' => [OpenPaymentState::Failed, true],
            'processing: in flight' => [OpenPaymentState::Processing, false],
            'paid, not yet recorded' => [OpenPaymentState::Paid, false],
        ];
    }

    /** @dataProvider statesAndWhetherItCanPay */
    public function testItCanPayByCardOnlyWhileNothingIsInFlightOrPaidAndUnrecorded(
        OpenPaymentState $state,
        bool $can,
    ): void {
        $this->owing();
        $this->provider->latest = new OpenPayment($state, new \DateTimeImmutable('2026-11-30 07:00:00'));

        $view = (new StatusRead())->forActive();

        self::assertSame($can, $view?->can->payCard);
        self::assertSame($state, $view?->cardPayment?->state);
        self::assertSame($can, str_contains($this->statusBlock(), 'Fizetés bankkártyával'));
    }

    public function testTheBareButtonLooksLikeTheFormsSubmitButton(): void
    {
        $this->owing();
        self::assertStringContainsString('<button type="submit" class="wp-element-button">', $this->statusBlock());

        $contributions = new Contributions();
        $contributions->paymentProvider($this->provider);
        $contributions->themeAdapter(new TestThemeAdapter(new ThemeAdaptation(
            [ElementRole::SubmitButton->value => ['et_pb_button']],
            errorColour: '#e02b20'
        )));
        Contributions::record($contributions);
        ThemeAdapters::reset();

        self::assertStringContainsString('class="wp-element-button et_pb_button"', $this->statusBlock());
        self::assertSame('wp-element-button et_pb_button', ThemeStyle::buttonClass());
        self::assertSame('#e02b20', ThemeStyle::errorColor());
        ThemeAdapters::reset();
    }

    public function testARefusedFormPostGoesBackWithOnlyTheCode(): void
    {
        $key = $this->stored('12A3');
        $this->activate($key);

        $response = $this->post(
            'application/x-www-form-urlencoded',
            'return_url=' . rawurlencode(home_url('/vissza/'))
        );

        self::assertSame(303, $response->get_status());
        self::assertSame(
            home_url('/vissza/') . '?kalaka_card=kalaka_card_payment_unavailable',
            $response->get_headers()['Location']
        );
        self::assertStringContainsString('no-store', (string) $response->get_headers()['Cache-Control']);
    }

    public function testARefusedFormPostWithAnotherSitesReturnAddressGoesToTheHomePage(): void
    {
        $this->activate(null);

        $response = $this->post(
            'application/x-www-form-urlencoded',
            'return_url=' . rawurlencode('https://elsewhere.example.net/')
        );

        self::assertSame(
            home_url('/') . '?kalaka_card=kalaka_card_payment_unavailable',
            $response->get_headers()['Location']
        );
    }

    public function testAnErrorThatIsNoKalakaRefusalReachesNobodyAsItsMessage(): void
    {
        $this->owing();
        $this->provider->payThrows = new \RuntimeException('Raw English <b>message</b> from the provider');

        $json = $this->post('application/json', '{}');
        $form = $this->post('application/x-www-form-urlencoded', '');

        self::assertSame(500, $json->get_status());
        self::assertSame('kalaka_system_failure', $json->get_data()['code'] ?? null);
        self::assertStringNotContainsString('Raw English', (string) wp_json_encode($json->get_data()));
        self::assertSame(303, $form->get_status());
        self::assertSame(
            home_url('/') . '?kalaka_card=kalaka_system_failure',
            $form->get_headers()['Location']
        );
    }
}
