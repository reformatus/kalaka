<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Payments;

use Kalaka\Forms\Api\ContentFrame;
use Kalaka\Forms\Api\ElementRole;
use Kalaka\Forms\Api\ThemeAdaptation;
use Kalaka\Forms\Engine\ThemeAdapters;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Payments\Api\CardAmountLimits;
use Kalaka\Payments\Api\OpenPayment;
use Kalaka\Payments\Api\OpenPaymentState;
use Kalaka\Portal\Api\StatusRead;
use Kalaka\Portal\Rest\CardPaymentRoute;
use Kalaka\Portal\Rest\CardReturnPage;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Forms\TestThemeAdapter;
use Kalaka\Tests\Integration\Portal\PortalTestCase;

/** The card payment route's amount and popup input, its return endpoint, and the status read's `cardAmounts`. */
final class CardPopupTest extends PortalTestCase
{
    private FakeProvider $provider;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        delete_option(CardAmountLimits::OPTION);
        $this->provider = new FakeProvider();
        $contributions = new Contributions();
        $contributions->paymentProvider($this->provider);
        Contributions::record($contributions);
        $GLOBALS['wp_rest_server'] = null;
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        delete_option(CardAmountLimits::OPTION);
        remove_all_filters(CardReturnPage::BARE_FILTER);
        ThemeAdapters::reset();
        $GLOBALS['wp_rest_server'] = null;

        parent::tear_down();
    }

    private function owing(string $amount = '5000'): RegistrationKey
    {
        $key = $this->stored('12A3');
        $this->charge($key, $amount);
        $this->activate($key);

        return $key;
    }

    /** @param array<string, mixed> $params */
    private function post(array $params, bool $json = false): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', '/kalaka-portal/v1/card-payment');
        $request->set_header('Content-Type', $json ? 'application/json' : 'application/x-www-form-urlencoded');
        $request->set_body_params($params + ['_wpnonce' => wp_create_nonce(CardPaymentRoute::NONCE_ACTION)]);

        return rest_do_request($request);
    }

    /** @param array<string, string> $params */
    private function back(array $params): \WP_REST_Response
    {
        $request = new \WP_REST_Request('GET', '/kalaka-portal/v1/card-payment/return');
        $request->set_query_params($params);

        return rest_do_request($request);
    }

    private function asked(int $index = 0): string
    {
        return rtrim(rtrim($this->provider->requests[$index]->lines[0]->amount->amount(), '0'), '.');
    }

    public function testTheOrderIsForTheAmountAskedFor(): void
    {
        $this->owing();

        $response = $this->post(['amount' => '2000'], true);

        self::assertSame(200, $response->get_status());
        self::assertSame('2000', $this->asked());
    }

    public function testAnAbsentAmountStillAsksForTheWholeBalance(): void
    {
        $this->owing();

        $this->post(['return_url' => home_url('/x/')]);

        self::assertSame('5000', $this->asked());
    }

    /** @return array<string, array{string}> */
    public static function refusedAmounts(): array
    {
        return ['above the maximum' => ['5001'], 'below the minimum' => ['999']];
    }

    /** @dataProvider refusedAmounts */
    public function testARefusedAmountIsItsCodeInJsonAndInTheFlashCookieForAFormPost(string $amount): void
    {
        $this->owing();

        $json = $this->post(['amount' => $amount], true);
        $form = $this->post(['amount' => $amount, 'return_url' => home_url('/vissza/')]);

        self::assertSame(422, $json->get_status());
        self::assertSame('kalaka_card_amount_refused', $json->get_data()['code'] ?? null);
        self::assertSame(303, $form->get_status());
        self::assertSame(home_url('/vissza/'), $form->get_headers()['Location']);
        self::assertStringStartsWith(
            'kalaka_card_refusal=kalaka_card_amount_refused;',
            (string) $form->get_headers()['Set-Cookie']
        );
        self::assertSame([], $this->provider->requests);
    }

    public function testAMalformedAmountIsInvalidInput(): void
    {
        $this->owing();

        foreach (['abc', '', ['1']] as $amount) {
            $response = $this->post(['amount' => $amount], true);
            self::assertSame('kalaka_invalid_input', $response->get_data()['code'] ?? null);
        }
        $form = $this->post(['amount' => 'abc']);
        self::assertStringStartsWith(
            'kalaka_card_refusal=kalaka_invalid_input;',
            (string) $form->get_headers()['Set-Cookie']
        );
    }

    public function testTheReturnUrlIsTheReturnEndpointCarryingTheStatusPageAndTheFlag(): void
    {
        $this->owing();

        $this->post(['return_url' => home_url('/jelentkezes/')]);
        $this->post(['return_url' => home_url('/jelentkezes/'), 'popup' => '1']);
        $this->post(['return_url' => home_url('/jelentkezes/'), 'popup' => '0']);

        $plain = rest_url('kalaka-portal/v1/card-payment/return');
        self::assertSame(
            add_query_arg(['to' => rawurlencode(home_url('/jelentkezes/'))], $plain),
            $this->provider->returnUrls[0]
        );
        self::assertSame(
            add_query_arg(['to' => rawurlencode(home_url('/jelentkezes/')), 'popup' => '1'], $plain),
            $this->provider->returnUrls[1]
        );
        self::assertSame($this->provider->returnUrls[0], $this->provider->returnUrls[2]);
        self::assertStringContainsString('popup=1', $this->provider->returnUrls[1]);
        self::assertStringNotContainsString('popup', $this->provider->returnUrls[0]);
    }

    /** @return array<string, array{string}> */
    public static function statusPages(): array
    {
        return [
            'a query string' => [home_url('/jelentkezes/') . '?a=1&b=2'],
            'a fragment' => [home_url('/jelentkezes/') . '#töredék'],
        ];
    }

    /**
     * A status page with its own query string or fragment stays one `to` argument: `rawurlencode`d when the
     * URL is built, decoded exactly once by the REST reader.
     *
     * @dataProvider statusPages
     */
    public function testTheReturnUrlCarriesAStatusPageWithItsOwnQueryStringOrFragment(string $statusPage): void
    {
        $url = CardPaymentRoute::returnUrl($statusPage, true);

        $query = wp_parse_url($url, PHP_URL_QUERY);
        self::assertIsString($query);
        wp_parse_str($query, $params);
        self::assertSame($statusPage, $params['to']);
    }

    public function testAPopupPostStillGetsThePayAddress(): void
    {
        $this->owing();

        $response = $this->post(['popup' => '1']);

        self::assertSame(303, $response->get_status());
        self::assertSame('https://pay.example.test/1', $response->get_headers()['Location']);
    }

    public function testARefusedPopupPostGoesToTheReturnEndpointWithTheFlagAndSetsTheCookie(): void
    {
        $this->owing();

        $response = $this->post(['amount' => '1', 'popup' => '1', 'return_url' => home_url('/jelentkezes/')]);

        self::assertSame(303, $response->get_status());
        self::assertSame(
            CardPaymentRoute::returnUrl(home_url('/jelentkezes/'), true),
            $response->get_headers()['Location']
        );
        self::assertStringStartsWith(
            'kalaka_card_refusal=kalaka_card_amount_refused;',
            (string) $response->get_headers()['Set-Cookie']
        );
    }

    public function testAJsonRefusalIgnoresThePopupFlag(): void
    {
        $this->activate(null);

        $response = $this->post(['popup' => '1'], true);

        self::assertSame(422, $response->get_status());
        self::assertArrayNotHasKey('Location', $response->get_headers());
    }

    public function testTheReturnWithoutThePopupFlagGoesToTheStatusPage(): void
    {
        $response = $this->back(['to' => home_url('/jelentkezes/')]);

        self::assertSame(303, $response->get_status());
        self::assertSame(home_url('/jelentkezes/'), $response->get_headers()['Location']);
        self::assertStringContainsString('no-store', (string) $response->get_headers()['Cache-Control']);
    }

    public function testAForeignOrMissingStatusPageGoesToTheHomePage(): void
    {
        $foreign = 'https://elsewhere.example.net/';
        foreach ([['to' => $foreign], [], ['to' => $foreign, 'popup' => '0']] as $params) {
            $response = $this->back($params);
            self::assertSame(303, $response->get_status());
            self::assertSame(home_url('/'), $response->get_headers()['Location']);
        }
    }

    public function testTheReturnWithThePopupFlagIsThePageInsideTheTheme(): void
    {
        $response = $this->back(['to' => home_url('/jelentkezes/'), 'popup' => '1']);

        self::assertSame(200, $response->get_status());
        self::assertStringContainsString('no-store', (string) $response->get_headers()['Cache-Control']);
        self::assertSame('noindex', $response->get_headers()['X-Robots-Tag'] ?? null);
        $html = (string) $response->get_data();
        self::assertStringContainsString('<main class="kalaka-card-return">', $html);
        // The body sits in the neutral content frame, inside the theme's header and footer.
        self::assertStringContainsString(
            '<div class="kalaka-card-return-frame"><main class="kalaka-card-return">',
            $html
        );
        // The frame closes after the body's inline script, so it wraps the whole body.
        self::assertStringContainsString("</script>\n</div>", $html);
        self::assertStringContainsString(
            '<p class="kalaka-card-return__text">Bezárhatod ezt a lapot.</p>',
            $html
        );
        self::assertStringContainsString(
            '<p><button type="button" class="wp-element-button" data-kalaka-close>Lap bezárása</button></p>',
            $html
        );
        self::assertStringContainsString(
            '<p><a href="' . esc_url(home_url('/jelentkezes/')) . '">Jelentkezésem állapota</a></p></main>',
            $html
        );
        self::assertStringContainsString(
            "window.opener.postMessage({type: 'kalaka-card-return'}, location.origin)",
            $html
        );
        self::assertStringContainsString('window.close()', $html);
        // The theme's own header and footer around it (the test theme's), not a bare document.
        self::assertStringStartsWith('Header', $html);
        self::assertStringContainsString("Footer\n", $html);
        self::assertStringNotContainsString('<!doctype', $html);
    }

    /** Makes a theme adapter that returns this (or throws it) the one in force. */
    private function adaptation(ThemeAdaptation|\Closure $returns): void
    {
        $contributions = new Contributions();
        $contributions->paymentProvider($this->provider);
        $contributions->themeAdapter(new TestThemeAdapter($returns));
        Contributions::record($contributions);
        ThemeAdapters::reset();
    }

    private function popup(): string
    {
        return (string) $this->back(['to' => home_url('/jelentkezes/'), 'popup' => '1'])->get_data();
    }

    public function testAnAdaptersPageFrameIsRenderedByCoreAroundTheWholeBody(): void
    {
        $this->adaptation(new ThemeAdaptation([], frame: ContentFrame::Page));

        $html = $this->popup();

        self::assertStringContainsString(
            '<div id="main-content"><div class="container"><div id="content-area" class="clearfix">'
            . '<div id="left-area"><main class="kalaka-card-return">',
            $html
        );
        self::assertStringContainsString("</script>\n</div></div></div></div>", $html);
        self::assertStringNotContainsString('kalaka-card-return-frame', $html);
    }

    public function testAnAdaptersBodyClassesAreAddedAndItsDroppedOnesTakenOffOnThisPageOnly(): void
    {
        $this->adaptation(new ThemeAdaptation(
            ['page_body' => ['full-width']],
            pageBodyOff: ['right-sidebar']
        ));
        self::assertSame(
            ['page', 'full-width'],
            CardReturnPage::bodyClass(['page', 'right-sidebar', 'full-width'])
        );

        // The filter is hooked around the render only.
        $this->popup();
        self::assertFalse(has_filter('body_class', [CardReturnPage::class, 'bodyClass']));
        self::assertFalse(has_filter('wp_robots', [CardReturnPage::class, 'robots']));
    }

    public function testAnAdapterValueOutsideTheClosedListsIsIgnoredAndTheNeutralFrameStays(): void
    {
        // A body class that is not one class name, and a role that does not exist: the adaptation is refused,
        // the adapter is skipped, and the page is as with no adapter.
        foreach (
            [
                static fn (): ThemeAdaptation => new ThemeAdaptation(
                    ['page_body' => ['x"><script>']],
                    frame: ContentFrame::Page
                ),
                static fn (): ThemeAdaptation => new ThemeAdaptation(['page_frame' => ['x']]),
                // A frame outside the closed list: `ContentFrame::from()` throws a `ValueError`, an `Error`.
                static fn (): ThemeAdaptation => new ThemeAdaptation([], frame: ContentFrame::from('free_html')),
                static fn (): ThemeAdaptation => new ThemeAdaptation([], pageBodyOff: ['a b']),
            ] as $malformed
        ) {
            $this->adaptation($malformed);

            $html = $this->popup();

            self::assertStringContainsString(
                '<div class="kalaka-card-return-frame"><main class="kalaka-card-return">',
                $html
            );
            self::assertStringNotContainsString('main-content', $html);
            self::assertSame([], ThemeAdapters::current()->classes(ElementRole::PageBody));
            self::assertSame(ContentFrame::Neutral, ThemeAdapters::current()->frame);
        }
    }

    public function testAnAdapterThatThrowsLeavesTheNeutralFrame(): void
    {
        $this->adaptation(static function (): ThemeAdaptation {
            throw new \RuntimeException('broken adapter');
        });

        self::assertStringContainsString('<div class="kalaka-card-return-frame">', $this->popup());
    }

    public function testABlockThemeHasItsOwnFrameAndTakesTheAdaptersBodyClasses(): void
    {
        if (!wp_get_theme('twentytwentyfive')->exists()) {
            self::markTestSkipped('No block theme installed.');
        }
        switch_theme('twentytwentyfive');
        $this->adaptation(new ThemeAdaptation(['page_body' => ['full-width']], frame: ContentFrame::Page));

        $html = $this->popup();

        self::assertTrue(wp_is_block_theme());
        self::assertStringStartsWith('<!doctype html>', $html);
        self::assertStringContainsString('<div class="wp-site-blocks">', $html);
        self::assertStringContainsString('<main class="kalaka-card-return">', $html);
        // No classic frame inside a block theme, and the adapter's body class on the body.
        self::assertStringNotContainsString('main-content', $html);
        self::assertStringNotContainsString('kalaka-card-return-frame', $html);
        self::assertMatchesRegularExpression('/<body class="[^"]*\bfull-width\b/', $html);
    }

    public function testTheBareFilterGivesAMinimalDocumentInTheSitesLanguage(): void
    {
        add_filter(CardReturnPage::BARE_FILTER, '__return_true');

        $html = (string) $this->back(['to' => home_url('/jelentkezes/'), 'popup' => '1'])->get_data();

        self::assertStringStartsWith('<!doctype html><html ', $html);
        self::assertStringContainsString('lang="', $html);
        self::assertStringContainsString('<meta charset="', $html);
        self::assertStringContainsString('<meta name="robots" content="noindex">', $html);
        self::assertStringContainsString('<main class="kalaka-card-return">', $html);
        // The bare page stays unwrapped: no frame, and no theme header or footer.
        self::assertStringNotContainsString('kalaka-card-return-frame', $html);
        self::assertStringNotContainsString('Header', $html);
        self::assertStringNotContainsString('Footer', $html);
    }

    public function testTheRobotsFilterMarksThePageNoindex(): void
    {
        self::assertSame(['noindex' => true], CardReturnPage::robots([]));
        self::assertSame(['index' => true, 'noindex' => true], CardReturnPage::robots(['index' => true]));
    }

    public function testAForeignStatusPageIsNotLinkedFromThePage(): void
    {
        $html = (string) $this->back(['to' => 'https://elsewhere.example.net/', 'popup' => '1'])->get_data();

        self::assertStringNotContainsString('elsewhere.example.net', $html);
        self::assertStringContainsString('<a href="' . esc_url(home_url('/')) . '">', $html);
    }

    public function testThePageIsServedAsHtmlAndTheRestIsLeftToJson(): void
    {
        $request = new \WP_REST_Request('GET', '/kalaka-portal/v1/card-payment/return');
        $route = new CardPaymentRoute();
        $page = new \WP_REST_Response('<p>page</p>', 200);

        ob_start();
        $served = $route->serveHtml(false, $page, $request);
        $echoed = ob_get_clean();

        self::assertTrue($served);
        self::assertSame('<p>page</p>', $echoed);
        $other = new \WP_REST_Request('GET', '/kalaka-portal/v1/other');
        self::assertFalse($route->serveHtml(false, $page, $other));
        self::assertFalse($route->serveHtml(false, new \WP_REST_Response(['a' => 1], 200), $request));
        self::assertFalse($route->serveHtml(false, new \WP_REST_Response('x', 303), $request));
    }

    public function testCardAmountsAreThereOnlyWhileItCanBePaidByCard(): void
    {
        $key = $this->owing('12000');

        $amounts = (new StatusRead())->forActive()?->cardAmounts;

        self::assertNotNull($amounts);
        self::assertSame('12000', rtrim(rtrim($amounts->full->amount(), '0'), '.'));
        self::assertSame('12000', rtrim(rtrim($amounts->max->amount(), '0'), '.'));
        self::assertSame('1000', rtrim(rtrim($amounts->min->amount(), '0'), '.'));
        self::assertNull($amounts->advance);

        $this->provider->latest = new OpenPayment(OpenPaymentState::Processing, new \DateTimeImmutable(), '501');
        self::assertFalse((new StatusRead())->forActive()?->can->payCard);
        self::assertNull((new StatusRead())->forActive()?->cardAmounts);

        $this->provider->latest = null;
        $this->provider->accepts = false;
        self::assertNull((new StatusRead())->forActive()?->cardAmounts);

        $this->provider->accepts = true;
        $this->pay($key, '12000');
        self::assertNull((new StatusRead())->forActive()?->cardAmounts);
    }

    public function testTheMinimumNeverExceedsTheBalanceAndFollowsTheSiteSetting(): void
    {
        $key = $this->owing('400');
        $amounts = (new StatusRead())->forActive()?->cardAmounts;
        self::assertSame('400', rtrim(rtrim((string) $amounts?->min->amount(), '0'), '.'));
        self::assertSame('400', rtrim(rtrim((string) $amounts?->full->amount(), '0'), '.'));

        $this->charge($key, '11600');
        update_option(CardAmountLimits::OPTION, ['HUF' => '2500']);
        $amounts = (new StatusRead())->forActive()?->cardAmounts;
        self::assertSame('2500', rtrim(rtrim((string) $amounts?->min->amount(), '0'), '.'));
        self::assertSame('12000', rtrim(rtrim((string) $amounts?->max->amount(), '0'), '.'));
    }

    public function testTheBareTestLinkStillStartsForTheWholeBalanceWithoutAnAmountField(): void
    {
        $this->owing();

        $html = $this->statusBlock();
        self::assertStringContainsString('Fizetés bankkártyával', $html);
        self::assertStringNotContainsString('name="amount"', $html);
        $this->post([]);
        self::assertSame('5000', $this->asked());
    }
}
