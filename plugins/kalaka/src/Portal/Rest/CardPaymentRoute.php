<?php

declare(strict_types=1);

namespace Kalaka\Portal\Rest;

use Kalaka\Forms\Api\FormCaching;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Payments\Api\CardPaymentUnavailable;
use Kalaka\Payments\Api\CardPayments;
use Kalaka\Portal\Api\ActiveRegistration;
use Kalaka\Portal\CardRefusalCookie;

/**
 * `POST kalaka-portal/v1/card-payment` `{return_url?, amount?, popup?}`: starts a card payment for the active
 * registration (`Payments\Api\CardPayments::start()`). A JSON request gets `{url}`; any other (a plain form post, no
 * script) gets a 303 to the pay URL. Open to anyone who holds the active registration, which only a secret link
 * gives and the signed cookie remembers; with none it is `CardPaymentUnavailable` (the portal's text for it).
 * A refused plain form post is answered with a 303 back to the same-site return address (else the referrer, else
 * the home page), clean of any query, with the refusal's code in a flash cookie (`CardRefusalCookie`) that the next
 * page request reads once. `amount` is a decimal text passed on to the start (absent asks for the whole payable
 * amount); `popup=1` says the page's script opened the popup window the post targets.
 *
 * The order always returns to `GET kalaka-portal/v1/card-payment/return?to=<status page>` (`popup=1` added when the
 * start came from a popup), the status page being that same-site address (`backTo()`). The return endpoint sends a
 * browser without the flag to the status page with a 303, and one with it the page of `CardReturnPage` (HTML, sent
 * by `rest_pre_serve_request`). A refused form post from a popup goes to that same return endpoint with the flag,
 * so the popup shows the close page and the refusal's flash cookie waits for the status page. Every answer carries
 * `Cache-Control: no-store`. It runs behind the error boundary (foundation.md, "Errors"): a JSON error is a
 * `KalakaException`'s code and its generic text, and anything else is `kalaka_system_failure`.
 */
final class CardPaymentRoute
{
    public const NAMESPACE = 'kalaka-portal/v1';

    public const ROUTE = '/card-payment';

    public const RETURN_ROUTE = '/card-payment/return';

    public function __construct(private readonly CardPayments $payments = new CardPayments())
    {
    }

    /** On `rest_api_init`. */
    public function register(): void
    {
        Boundary::route(self::NAMESPACE, self::ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'start'],
            'permission_callback' => '__return_true',
        ]);
        Boundary::route(self::NAMESPACE, self::RETURN_ROUTE, [
            'methods' => 'GET',
            'callback' => [$this, 'back'],
            'permission_callback' => '__return_true',
        ]);
        add_filter('rest_pre_serve_request', [$this, 'serveHtml'], 10, 3);
    }

    /**
     * The return endpoint's address for a status page (`popup=1` added when the start came from a popup): what the
     * order returns to. The status page is `rawurlencode`d, so its own query string and fragment stay one `to`
     * argument (the REST reader decodes it once).
     */
    public static function returnUrl(string $statusPage, bool $popup): string
    {
        return add_query_arg(
            ['to' => rawurlencode($statusPage), ...($popup ? ['popup' => '1'] : [])],
            rest_url(self::NAMESPACE . self::RETURN_ROUTE)
        );
    }

    /** The route's address, for the form that posts to it. */
    public static function url(): string
    {
        return rest_url(self::NAMESPACE . self::ROUTE);
    }

    /**
     * @internal the route's callback
     * @throws \Throwable on a JSON request: the error boundary answers it with the error's code and the generic text
     *     of its kind, a refusal with its own code, anything unknown as `kalaka_system_failure`, never a raw message
     */
    public function start(\WP_REST_Request $request): \WP_REST_Response
    {
        $json = ($request->get_content_type()['value'] ?? '') === 'application/json';
        $returnUrl = $request->get_param('return_url');
        $returnUrl = is_string($returnUrl) && $returnUrl !== '' ? $returnUrl : null;
        $popup = (string) $request->get_param('popup') === '1';
        $statusPage = $this->backTo($returnUrl);
        $returnTo = self::returnUrl($statusPage, $popup);
        $refusal = null;
        try {
            LoadState::current()->requireOpen();
            $key = ActiveRegistration::current();
            if ($key === null) {
                throw new CardPaymentUnavailable();
            }
            $url = $this->payments->start($key, $returnTo, $this->amountOf($request->get_param('amount')));
        } catch (\Throwable $e) {
            if ($json) {
                throw $e;
            }

            // A plain form post has no script to show an error: back to the clean page, the error's code in a
            // flash cookie (never in the address, so a reload does not show the refusal again).
            $refusal = Boundary::shown('REST card payment', $e)->errorCode();
            // From a popup the refusal shows on the close page; the status page shows it from the cookie.
            $url = $popup ? $returnTo : $statusPage;
        }

        $response = $json ? new \WP_REST_Response(['url' => $url], 200) : new \WP_REST_Response(null, 303);
        if (!$json) {
            $response->header('Location', $url);
            if ($refusal !== null) {
                $response->header('Set-Cookie', CardRefusalCookie::header($refusal));
            }
        }
        $response->header('Cache-Control', FormCaching::CACHE_CONTROL);

        return $response;
    }

    /**
     * The amount asked for: a decimal text (an integer is taken as its text); absent asks for the whole payable
     * amount, an empty text is malformed.
     *
     * @throws InvalidInput when it is anything else
     */
    private function amountOf(mixed $amount): ?string
    {
        if ($amount === null) {
            return null;
        }
        if (is_int($amount)) {
            return (string) $amount;
        }
        if (!is_string($amount)) {
            throw new InvalidInput();
        }

        return $amount;
    }

    /**
     * `GET kalaka-portal/v1/card-payment/return`: where the order returns to. Without `popup=1` a 303 to the status
     * page (`to`, same-site, else the home page); with it the close page, HTML, no-store and noindex.
     *
     * @internal the route's callback
     */
    public function back(\WP_REST_Request $request): \WP_REST_Response
    {
        $to = $request->get_param('to');
        $statusPage = is_string($to) ? wp_validate_redirect($to, '') : '';
        $statusPage = $statusPage === '' ? home_url('/') : $statusPage;
        if ((string) $request->get_param('popup') !== '1') {
            $response = new \WP_REST_Response(null, 303);
            $response->header('Location', $statusPage);
        } else {
            $response = new \WP_REST_Response(CardReturnPage::render($statusPage), 200);
            $response->header('X-Robots-Tag', 'noindex');
        }
        $response->header('Cache-Control', FormCaching::CACHE_CONTROL);

        return $response;
    }

    /**
     * @internal the `rest_pre_serve_request` filter: the close page's answer is HTML, not JSON
     * @param mixed $served
     * @param mixed $result
     * @param mixed $request
     * @return mixed
     */
    public function serveHtml($served, $result, $request)
    {
        if (
            !$request instanceof \WP_REST_Request || !$result instanceof \WP_REST_Response
            || $request->get_route() !== '/' . self::NAMESPACE . self::RETURN_ROUTE
            || $result->get_status() !== 200 || !is_string($result->get_data())
        ) {
            return $served;
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the page's own markup, escaped where built
        echo $result->get_data();

        return true;
    }

    /** The same-site return address, else the page the post came from, else the home page. */
    private function backTo(?string $returnUrl): string
    {
        foreach ([$returnUrl, wp_get_referer() ?: null] as $candidate) {
            $valid = $candidate === null ? '' : wp_validate_redirect($candidate, '');
            if ($valid !== '') {
                return $valid;
            }
        }

        return home_url('/');
    }
}
