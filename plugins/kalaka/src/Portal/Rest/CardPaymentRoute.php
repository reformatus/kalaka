<?php

declare(strict_types=1);

namespace Kalaka\Portal\Rest;

use Kalaka\Forms\Api\FormCaching;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Payments\Api\CardPaymentUnavailable;
use Kalaka\Payments\Api\CardPayments;
use Kalaka\Portal\Api\ActiveRegistration;
use Kalaka\Portal\CardRefusalCookie;

/**
 * `POST kalaka-portal/v1/card-payment` `{return_url?}`: starts a card payment for the active registration
 * (`Payments\Api\CardPayments::start()`). A JSON request gets `{url}`; any other (a plain form post, no
 * script) gets a 303 to the pay URL. Open to anyone who holds the active registration, which only a secret link
 * gives and the signed cookie remembers; with none it is `CardPaymentUnavailable` (the portal's text for it).
 * A refused plain form post is answered with a 303 back to the same-site return address (else the referrer, else
 * the home page), clean of any query, with the refusal's code in a flash cookie (`CardRefusalCookie`) that the next
 * page request reads once. Every answer carries `Cache-Control: no-store`. It runs
 * behind the error boundary (foundation.md, "Errors"): a JSON error is a `KalakaException`'s code and its generic
 * text, and anything else is `kalaka_system_failure`.
 */
final class CardPaymentRoute
{
    public const NAMESPACE = 'kalaka-portal/v1';

    public const ROUTE = '/card-payment';

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
        $refusal = null;
        try {
            LoadState::current()->requireOpen();
            $key = ActiveRegistration::current();
            if ($key === null) {
                throw new CardPaymentUnavailable();
            }
            $url = $this->payments->start($key, $returnUrl);
        } catch (\Throwable $e) {
            if ($json) {
                throw $e;
            }

            // A plain form post has no script to show an error: back to the clean page, the error's code in a
            // flash cookie (never in the address, so a reload does not show the refusal again).
            $refusal = Boundary::shown('REST card payment', $e)->errorCode();
            $url = $this->backTo($returnUrl);
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
