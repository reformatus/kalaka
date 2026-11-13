<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Admin\Api\AdminAccess;

/**
 * The REST controller of `kalaka_form`, which the block editor uses. WordPress's own lets anyone read a
 * published post of a type in REST; a form's definition is for full access only, so every read needs
 * `kalaka_full_access` too. Writes already need it through the post type's capabilities.
 */
final class FormRestController extends \WP_REST_Posts_Controller
{
    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     * @return bool|\WP_Error
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress's method name
    public function get_items_permissions_check($request): bool|\WP_Error
    {
        return current_user_can(AdminAccess::FULL_ACCESS) ? parent::get_items_permissions_check($request) : false;
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     * @return bool|\WP_Error
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress's method name
    public function get_item_permissions_check($request): bool|\WP_Error
    {
        return current_user_can(AdminAccess::FULL_ACCESS) ? parent::get_item_permissions_check($request) : false;
    }

    /**
     * A forced delete of a form that submissions refer to is refused (`FormDeletion`); moving it to the
     * trash is not.
     *
     * @param \WP_REST_Request<array<string, mixed>> $request
     * @return \WP_REST_Response|\WP_Error
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress's method name
    public function delete_item($request): \WP_REST_Response|\WP_Error
    {
        $id = (int) $request->get_param('id');
        if ((bool) $request->get_param('force') && FormDeletion::hasSubmissions($id)) {
            return new \WP_Error(FormDeletion::CODE, FormDeletion::MESSAGE, ['status' => 409]);
        }

        return parent::delete_item($request);
    }
}
