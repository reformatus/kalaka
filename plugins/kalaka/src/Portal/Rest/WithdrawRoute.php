<?php

declare(strict_types=1);

namespace Kalaka\Portal\Rest;

use Kalaka\Forms\Api\FormCaching;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Portal\Api\ActiveRegistration;
use Kalaka\Portal\Api\Withdrawal;
use Kalaka\Portal\WithdrawRefusalCookie;

/**
 * `POST kalaka-portal/v1/withdraw` `{code, return_url?, preview?, kalaka_withdraw_nonce}`: the participant's own
 * withdrawal for the active registration (`Portal\Api\Withdrawal`). A JSON request gets `{changed, status}`, or with
 * `preview: true` `{allowed, paid, owed_after}` (the registrant's own payer's money, nothing written); any other (a
 * plain form post, no script) gets a 303 to the same-site return address (else the referrer, else the home page),
 * clean of any query, with a refusal's code in a flash cookie (`WithdrawRefusalCookie`) that the next page request
 * reads once. A plain form post is never a preview. Open to anyone who holds the active registration, which only a
 * secret link gives and the signed cookie remembers, and a real withdrawal carries that page's nonce
 * (`Withdrawal::nonceField()`, in `kalaka_withdraw_nonce`), bound to the registration's own code (N1166). Every
 * answer carries `Cache-Control: no-store`. It runs behind the error boundary (foundation.md, "Errors"): a JSON
 * error is a `KalakaException`'s code and its generic text, and anything else is `kalaka_system_failure`.
 * [UNAPPROVED N660, N665, N1166]
 */
final class WithdrawRoute
{
    public const NAMESPACE = 'kalaka-portal/v1';

    public const ROUTE = '/withdraw';

    public function __construct(private readonly Withdrawal $withdrawal = new Withdrawal())
    {
    }

    /** On `rest_api_init`. */
    public function register(): void
    {
        Boundary::route(self::NAMESPACE, self::ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'withdraw'],
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
    public function withdraw(\WP_REST_Request $request): \WP_REST_Response
    {
        $json = ($request->get_content_type()['value'] ?? '') === 'application/json';
        $returnUrl = $request->get_param('return_url');
        $returnUrl = is_string($returnUrl) && $returnUrl !== '' ? $returnUrl : null;
        $code = $request->get_param('code');
        $preview = $json && $request->get_param('preview') === true;
        $nonce = $request->get_param(Withdrawal::NONCE_FIELD);
        $result = [];
        $refusal = null;
        try {
            LoadState::current()->requireOpen();
            $result = $this->withdrawal->run(
                ActiveRegistration::current(),
                is_string($code) ? $code : '',
                $preview,
                is_string($nonce) ? $nonce : null
            );
        } catch (\Throwable $e) {
            if ($json) {
                throw $e;
            }

            // A plain form post has no script to show an error: back to the clean page, the error's code in a
            // flash cookie (never in the address, so a reload does not show the refusal again).
            $refusal = Boundary::shown('REST withdraw', $e)->errorCode();
        }

        if ($json) {
            $response = new \WP_REST_Response($result, 200);
        } else {
            $response = new \WP_REST_Response(null, 303);
            $response->header('Location', $this->backTo($returnUrl));
            if ($refusal !== null) {
                $response->header('Set-Cookie', WithdrawRefusalCookie::header($refusal));
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
