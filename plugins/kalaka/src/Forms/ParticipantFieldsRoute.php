<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Api\ParticipantField;
use Kalaka\Forms\ParticipantField\RenameParticipantField;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\UseCase\Runner;

/**
 * `GET kalaka-admin/v1/events/{id}/participant-fields`: the event's participant fields for the form editor,
 * which only full access opens. `include_retired` adds the retired ones. [UNAPPROVED N2]
 *
 * `PATCH kalaka-admin/v1/events/{id}/participant-fields/{key}` with `{title?, options?: [{value, label}]}`:
 * renames one or its options, through `RenameParticipantField`, and answers with the field as the read
 * shows it. [UNAPPROVED N10] [UNAPPROVED N11]
 */
final class ParticipantFieldsRoute
{
    public const NAMESPACE = 'kalaka-admin/v1';
    public const ROUTE = '/events/(?P<id>[1-9][0-9]*)/participant-fields';
    public const ONE = self::ROUTE . '/(?P<key>[a-z][a-z0-9_]{0,63})';

    /** `rest_api_init` */
    public static function register(): void
    {
        Boundary::route(self::NAMESPACE, self::ROUTE, [
            'methods' => 'GET',
            'callback' => [self::class, 'read'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'id' => ['type' => 'integer', 'minimum' => 1, 'required' => true],
                'include_retired' => ['type' => 'boolean', 'default' => false],
            ],
        ]);
        Boundary::route(self::NAMESPACE, self::ONE, [
            'methods' => 'PATCH',
            'callback' => [self::class, 'rename'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'id' => ['type' => 'integer', 'minimum' => 1, 'required' => true],
                'key' => ['type' => 'string', 'required' => true],
                'title' => ['type' => 'string'],
                'options' => ['type' => 'array'],
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
        $fields = (new Forms())->participantFields($eventId, (bool) $request->get_param('include_retired'));

        return new \WP_REST_Response(array_map(static fn (ParticipantField $f): array => $f->toArray(), $fields));
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     * @throws NotFound for an event or a key that does not exist
     */
    public static function rename(\WP_REST_Request $request): \WP_REST_Response
    {
        $eventId = (int) $request->get_param('id');
        $key = (string) $request->get_param('key');
        if ((new Events())->find($eventId) === null) {
            throw new NotFound();
        }
        $input = ['event_id' => $eventId, 'key' => $key];
        foreach (['title', 'options'] as $name) {
            if ($request->has_param($name)) {
                $input[$name] = $request->get_param($name);
            }
        }
        Runner::current()->run(new RenameParticipantField(), $input, new UserActor(get_current_user_id()));
        foreach ((new Forms())->participantFields($eventId, true) as $field) {
            if ($field->key === $key) {
                return new \WP_REST_Response($field->toArray());
            }
        }

        throw new NotFound();
    }
}
