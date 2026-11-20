<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

use Kalaka\Admin\Api\Access;
use Kalaka\Foundation\Api\Entry\Boundary;

/**
 * The `woocommerce_prevent_admin_access` filter: WooCommerce redirects a user it would keep out of
 * wp-admin; a user who may reach Kaláka's admin side (full access or any grant) is not redirected.
 * Everyone else keeps WooCommerce's own answer.
 *
 * Failure isolation: the filter runs on `admin_init`, outside any transaction. If core's API is
 * absent or anything throws, the incoming value is returned unchanged, so WooCommerce's decision
 * stands and the admin never goes down on this plugin's account.
 */
final class AdminAccessGate
{
    /** @param class-string $access core's admin-side read, `Kalaka\Admin\Api\Access` */
    public function __construct(private readonly string $access = Access::class)
    {
    }

    /**
     * @internal the `woocommerce_prevent_admin_access` filter
     * @param mixed $prevent
     * @return mixed
     */
    public function prevent($prevent)
    {
        if (!class_exists($this->access) || !is_callable([$this->access, 'mayReachAdmin'])) {
            return $prevent;
        }

        try {
            /** @var callable(int): bool $mayReach */
            $mayReach = [$this->access, 'mayReachAdmin'];
            if ((bool) $mayReach(get_current_user_id())) {
                return false;
            }
        } catch (\Throwable $e) {
            try {
                if (class_exists(Boundary::class)) {
                    Boundary::report('deciding whether a user may reach Kaláka\'s admin side', $e);
                }
            } catch (\Throwable) {
                // Failure isolation: even the report must not take the admin down.
            }
        }

        return $prevent;
    }
}
