<?php

declare(strict_types=1);

namespace Kalaka\Assignments\Rest;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Assignments\Api\AssignmentOption;
use Kalaka\Assignments\Api\AssignmentSet;
use Kalaka\Assignments\Api\AssignmentSets;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;

/**
 * `GET kalaka-admin/v1/events/{id}/assignment-sets`: the event's sets for the form editor, where a request
 * field (the module ranking) names its set: each `{id, name, request_field_key, options: [{value, label}]}`,
 * in ID order. Only full access opens the form editor, so only full access reads it. A plain read.
 * [UNAPPROVED N8]
 */
final class AssignmentSetsRoute
{
    public const NAMESPACE = 'kalaka-admin/v1';
    public const ROUTE = '/events/(?P<id>[1-9][0-9]*)/assignment-sets';

    /** `rest_api_init` */
    public static function register(): void
    {
        Boundary::route(self::NAMESPACE, self::ROUTE, [
            'methods' => 'GET',
            'callback' => [self::class, 'read'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'id' => ['type' => 'integer', 'minimum' => 1, 'required' => true],
            ],
        ]);
    }

    /** @throws PermissionDenied without full access */
    public static function permission(): bool
    {
        if (!current_user_can(AdminAccess::FULL_ACCESS)) {
            throw new PermissionDenied();
        }

        return true;
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     * @throws NotFound for an event that does not exist
     */
    public static function read(\WP_REST_Request $request): \WP_REST_Response
    {
        $eventId = (int) $request->get_param('id');
        if ((new Events())->find($eventId) === null) {
            throw new NotFound();
        }

        return new \WP_REST_Response(array_map(
            static fn (AssignmentSet $set): array => [
                'id' => $set->id,
                'name' => $set->name,
                'request_field_key' => $set->requestFieldKey,
                'options' => array_map(
                    static fn (AssignmentOption $option): array => ['value' => $option->key, 'label' => $option->label],
                    $set->options
                ),
            ],
            (new AssignmentSets())->ofEvent($eventId)
        ));
    }
}
