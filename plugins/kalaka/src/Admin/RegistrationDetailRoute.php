<?php

declare(strict_types=1);

namespace Kalaka\Admin;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\Api\RegistrationDetail;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\PermissionDenied;

/**
 * `GET kalaka-admin/v1/events/{event}/registrations/{code}`: the detail of one registration, as
 * `Api\RegistrationDetail` reads it for the actor's grants and views. A code the actor's views do not hold is
 * a 404, like one that does not exist. The code is the documented public identifier, not a secret: the view
 * decides what is shown. [UNAPPROVED Access]
 */
final class RegistrationDetailRoute
{
    public const NAMESPACE = 'kalaka-admin/v1';
    public const ROUTE = '/events/(?P<event>[1-9][0-9]*)/registrations/(?P<code>[0-9]{2}[AKBEGJTZFLRSXI][0-9]{1,13})';

    /** `rest_api_init` */
    public static function register(): void
    {
        Boundary::route(self::NAMESPACE, self::ROUTE, [
            'methods' => 'GET',
            'callback' => [self::class, 'read'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'event' => ['type' => 'integer', 'minimum' => 1, 'required' => true],
                'code' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    /** @throws PermissionDenied for a user with neither full access nor a grant on any event */
    public static function permission(): bool
    {
        if (!Access::mayReachAdmin(get_current_user_id())) {
            throw new PermissionDenied();
        }

        return true;
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     * @throws \Kalaka\Foundation\Api\Error\NotFound
     */
    public static function read(\WP_REST_Request $request): \WP_REST_Response
    {
        $detail = (new RegistrationDetail())->of(
            new UserActor(get_current_user_id()),
            (int) $request->get_param('event'),
            (string) $request->get_param('code')
        );

        return new \WP_REST_Response($detail);
    }
}
