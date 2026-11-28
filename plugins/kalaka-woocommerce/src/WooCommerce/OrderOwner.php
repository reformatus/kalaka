<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Payments\Api\PaymentRequest;
use Kalaka\Registrations\Api\Registrations;

/**
 * The WordPress user a new order belongs to, or 0 for a guest order. [UNAPPROVED N327]
 *
 * WooCommerce's pay page lets only the order's own customer pay a customer order: a logged-out visitor is sent to
 * the login form and another user is refused (`wc_customer_has_capability()`, `pay_for_order`), while a guest order
 * (customer 0) is open to anyone with its key. So an order is linked to the owner account of its registrations only
 * when the paying visitor is logged in as that very user, and every other order is a guest order (a registrant who
 * follows the secret link logged out must still be able to pay).
 *
 * The owner is that of every line's registration, all the same; lines of several owners, a line without one, or
 * an unreadable registration make a guest order.
 *
 * The visitor is read from the logged-in cookie, read-only: the card start is a plain form post to a REST route
 * with no `wp_rest` nonce, and REST treats a cookie-authenticated request without one as logged out
 * (`rest_cookie_check_errors()` sets user 0), so `get_current_user_id()` would be 0 there. The cookie is validated
 * with its signature and session token (`wp_validate_auth_cookie()`); nobody is logged in by it, and the result
 * decides nothing but the customer of the order, which has to be the owner of the registrations already.
 */
final class OrderOwner
{
    public function __construct(private ?Registrations $registrations = null)
    {
    }

    public function customerOf(PaymentRequest $request): int
    {
        try {
            $owner = null;
            foreach ($request->lines as $line) {
                $registrations = $this->registrations ??= new Registrations();
                $id = $registrations->find($line->registration)?->ownerUserId;
                if ($id === null || $id <= 0 || ($owner !== null && $owner !== $id)) {
                    return 0;
                }
                $owner = $id;
            }

            return $owner !== null && $owner === $this->visitor() ? $owner : 0;
        } catch (\Throwable $e) {
            Boundary::report('finding the owner of a card payment order', $e);

            return 0;
        }
    }

    /** The user the request's logged-in cookie names, or 0; never logs anyone in. */
    private function visitor(): int
    {
        $id = get_current_user_id();
        if ($id > 0) {
            return $id;
        }
        $valid = wp_validate_auth_cookie('', 'logged_in');

        return $valid === false ? 0 : (int) $valid;
    }
}
