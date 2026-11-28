<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Payments;

use Kalaka\Forms\Api\ElementRole;
use Kalaka\Forms\Api\ThemeAdaptation;
use Kalaka\Forms\Api\ThemeStyle;
use Kalaka\Forms\Engine\ThemeAdapters;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Payments\Api\OpenPayment;
use Kalaka\Payments\Api\OpenPaymentState;
use Kalaka\Portal\Api\StatusRead;
use Kalaka\Portal\CardRefusalCookie;
use Kalaka\Portal\Rest\CardPaymentRoute;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Forms\TestThemeAdapter;
use Kalaka\Tests\Integration\Portal\PortalTestCase;
use Kalaka\Tests\Integration\Portal\RecordingResponse;

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
        $this->provider->latest = new OpenPayment(OpenPaymentState::Failed, $since, '501');

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

    public function testTheBareLinkIsAPostFormCarryingThePagesAddressAsTheReturnUrlOnASingularPageOnly(): void
    {
        $this->owing();

        // Not on a singular page: the form posts, and carries no return address (the route falls back).
        $this->go_to(home_url('/'));
        $off = $this->statusBlock();
        self::assertStringContainsString('<form class="kalaka-pay-card" method="post" action="', $off);
        self::assertStringNotContainsString('name="return_url"', $off);

        $page = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $this->go_to((string) get_permalink($page));
        $on = $this->statusBlock();
        self::assertStringContainsString('method="post"', $on);
        self::assertStringContainsString(
            '<input type="hidden" name="return_url" value="' . esc_attr((string) get_permalink($page)) . '">',
            $on
        );
    }

    /** @param array<string, mixed> $params */
    private function post(string $contentType, string $body): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', '/kalaka-portal/v1/card-payment');
        $request->set_header('Content-Type', $contentType);
        $request->set_body($body);
        if ($contentType === 'application/json') {
            $params = (array) json_decode($body, true);
        } else {
            $params = [];
            parse_str($body, $params);
        }
        // Every ordinary post carries the page's nonce, as the rendered form and a script do.
        $params['_wpnonce'] = wp_create_nonce(CardPaymentRoute::NONCE_ACTION);
        $request->set_body_params($params);

        return rest_do_request($request);
    }

    public function testAValidNonceStartsThePaymentInJsonAndInAFormPost(): void
    {
        $this->owing();

        $json = $this->post('application/json', '{}');
        $form = $this->post('application/x-www-form-urlencoded', '');

        self::assertSame(200, $json->get_status());
        self::assertSame(['url' => 'https://pay.example.test/1'], $json->get_data());
        self::assertSame(303, $form->get_status());
        self::assertCount(2, $this->provider->requests);
    }

    public function testAJsonPostWithTheNonceInTheHeaderStartsThePayment(): void
    {
        $this->owing();
        $request = new \WP_REST_Request('POST', '/kalaka-portal/v1/card-payment');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('X-WP-Nonce', wp_create_nonce(CardPaymentRoute::NONCE_ACTION));
        $request->set_body_params([]);

        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());
        self::assertCount(1, $this->provider->requests);
    }

    /** @return array<string, array{string, bool}> */
    public static function missingOrBadNonce(): array
    {
        return [
            'JSON without a nonce' => ['application/json', false],
            'JSON with a bad nonce' => ['application/json', true],
            'a form post without a nonce' => ['application/x-www-form-urlencoded', false],
            'a form post with a bad nonce' => ['application/x-www-form-urlencoded', true],
        ];
    }

    /** @dataProvider missingOrBadNonce */
    public function testAMissingOrBadNonceIsRefusedOnBothFormAndJson(string $contentType, bool $bad): void
    {
        $this->owing();
        $request = new \WP_REST_Request('POST', '/kalaka-portal/v1/card-payment');
        $request->set_header('Content-Type', $contentType);
        $request->set_body_params($bad ? ['_wpnonce' => 'kalaka_wrong'] : []);

        $response = rest_do_request($request);

        self::assertSame([], $this->provider->requests);
        if ($contentType === 'application/json') {
            self::assertSame(422, $response->get_status());
            self::assertSame('kalaka_card_nonce_invalid', $response->get_data()['code'] ?? null);
        } else {
            self::assertSame(303, $response->get_status());
            self::assertStringStartsWith(
                'kalaka_card_refusal=kalaka_card_nonce_invalid;',
                (string) $response->get_headers()['Set-Cookie']
            );
        }
    }

    public function testAJsonPostGivesTheUrl(): void
    {
        $this->owing();

        $response = $this->post('application/json', (string) wp_json_encode(['return_url' => home_url('/vissza/')]));

        self::assertSame(200, $response->get_status());
        self::assertSame(['url' => 'https://pay.example.test/1'], $response->get_data());
        self::assertSame(CardPaymentRoute::returnUrl(home_url('/vissza/'), false), $this->provider->returnUrls[0]);
        self::assertStringContainsString('no-store', (string) $response->get_headers()['Cache-Control']);
    }

    public function testAFormPostIsRedirectedWith303(): void
    {
        $this->owing();

        $response = $this->post('application/x-www-form-urlencoded', 'return_url=' . rawurlencode(home_url('/x/')));

        self::assertSame(303, $response->get_status());
        self::assertSame('https://pay.example.test/1', $response->get_headers()['Location']);
        self::assertArrayNotHasKey('Set-Cookie', $response->get_headers());
    }

    public function testAReturnAddressOnAnotherSiteIsNotUsed(): void
    {
        $this->owing();

        $this->post('application/json', (string) wp_json_encode(['return_url' => 'https://elsewhere.example.net/']));

        self::assertSame(CardPaymentRoute::returnUrl(home_url('/'), false), $this->provider->returnUrls[0]);
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
            'pending: the next start replaces the order' => [OpenPaymentState::Pending, true],
            'failed: the next start replaces the order' => [OpenPaymentState::Failed, true],
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
        $this->provider->latest = new OpenPayment($state, new \DateTimeImmutable('2026-11-30 07:00:00'), '501');

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
        self::assertSame(home_url('/vissza/'), $response->get_headers()['Location']);
        $cookie = (string) $response->get_headers()['Set-Cookie'];
        self::assertStringStartsWith(
            'kalaka_card_refusal=kalaka_card_payment_unavailable; Max-Age=120; Path=/',
            $cookie
        );
        self::assertStringContainsString('HttpOnly; SameSite=Lax', $cookie);
        self::assertStringContainsString('no-store', (string) $response->get_headers()['Cache-Control']);
    }

    public function testARefusedFormPostWithAnotherSitesReturnAddressGoesToTheHomePage(): void
    {
        $this->activate(null);

        $response = $this->post(
            'application/x-www-form-urlencoded',
            'return_url=' . rawurlencode('https://elsewhere.example.net/')
        );

        self::assertSame(home_url('/'), $response->get_headers()['Location']);
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
        self::assertSame(home_url('/'), $form->get_headers()['Location']);
        self::assertStringStartsWith(
            'kalaka_card_refusal=kalaka_system_failure;',
            (string) $form->get_headers()['Set-Cookie']
        );
    }

    public function testTheRefusalIsShownOnceByTheNextStatusReadAndTheCookieIsExpired(): void
    {
        $this->owing();
        $response = new RecordingResponse();

        CardRefusalCookie::read(['kalaka_card_refusal' => 'kalaka_card_payment_in_flight'], $response);

        self::assertSame(['', -1], $response->cookies['kalaka_card_refusal']);
        self::assertSame('kalaka_card_payment_in_flight', (new StatusRead())->forActive()?->cardRefusal);
        // Every block of the same request sees it (the transfer block may render first).
        self::assertSame('kalaka_card_payment_in_flight', (new StatusRead())->forActive()?->cardRefusal);

        // The next request: the cookie is gone, so is the refusal.
        CardRefusalCookie::read([], new RecordingResponse());
        self::assertNull((new StatusRead())->forActive()?->cardRefusal);
    }

    public function testTheCookieIsReadAfterCanonicalRedirection(): void
    {
        $found = false;
        foreach ($GLOBALS['wp_filter']['template_redirect']->callbacks[11] ?? [] as $callback) {
            $function = $callback['function'];
            $found = $found || (is_array($function) && $function[0] instanceof \Kalaka\Portal\Module
                && $function[1] === 'readCardRefusal');
        }
        self::assertTrue($found);
        self::assertSame(10, has_action('template_redirect', 'redirect_canonical'));
    }

    public function testACookieValueThatIsNoKnownCodeIsDroppedAndExpired(): void
    {
        $this->owing();
        $response = new RecordingResponse();

        CardRefusalCookie::read(['kalaka_card_refusal' => '<b>hack</b>'], $response);

        self::assertSame(['', -1], $response->cookies['kalaka_card_refusal']);
        self::assertNull((new StatusRead())->forActive()?->cardRefusal);
    }

    public function testNoCookieMeansNoRefusalAndSetsNothing(): void
    {
        $this->owing();
        $response = new RecordingResponse();

        CardRefusalCookie::read([], $response);

        self::assertSame([], $response->cookies);
        self::assertNull((new StatusRead())->forActive()?->cardRefusal);
    }

    public function testAPaidRequestCountsAsInFlightUntilItsLinesAreRecorded(): void
    {
        $key = $this->owing();
        $this->provider->latest = new OpenPayment(
            OpenPaymentState::Paid,
            new \DateTimeImmutable('2026-11-30 09:30:00'),
            '501'
        );
        self::assertFalse((new StatusRead())->forActive()?->can->payCard);

        $this->record($this->line(
            $key,
            LineKind::Payment,
            '-5000',
            'online:fake:order:501:71',
            paidAt: '2026-11-30 09:30:00',
            sourceType: 'fake_order',
            source: '501'
        ));
        $this->charge($key, '3000');

        self::assertTrue((new StatusRead())->forActive()?->can->payCard);
    }
}
