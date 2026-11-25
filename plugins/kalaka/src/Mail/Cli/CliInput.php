<?php

declare(strict_types=1);

namespace Kalaka\Mail\Cli;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Entry\CommandFailure;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;

/**
 * What the action-mail commands (list, show, body, on, off, layout) read from their arguments, in one place
 * (module-internal): the event ID, the output format and the read guard. It parses input and holds no rule of
 * the use cases.
 */
final class CliInput
{
    /**
     * The event ID of the first argument, which must be a positive whole number. The use case that follows
     * refuses an event that does not exist.
     *
     * @param list<string> $args
     * @throws CommandFailure when it is no positive whole number
     */
    public static function eventId(array $args): int
    {
        $given = $args[0] ?? '';
        if (preg_match('/\A[1-9][0-9]*\z/', $given) !== 1) {
            throw new CommandFailure('The event ID must be a positive whole number.');
        }

        return (int) $given;
    }

    /**
     * The event ID of the first argument for a command with no use case to refuse an unknown event.
     *
     * @param list<string> $args
     * @throws CommandFailure when it is no positive whole number
     * @throws NotFound when there is no such event
     */
    public static function existingEventId(array $args): int
    {
        $eventId = self::eventId($args);
        (new Events())->find($eventId) ?? throw new NotFound();

        return $eventId;
    }

    /**
     * @param array<string, mixed> $assocArgs
     * @throws CommandFailure when the format is none of the three
     */
    public static function format(array $assocArgs): string
    {
        $format = $assocArgs['format'] ?? 'table';
        if (!in_array($format, ['table', 'json', 'csv'], true)) {
            throw new CommandFailure('The format must be table, json or csv.');
        }

        return $format;
    }

    /** @throws PermissionDenied unless the command line, or its user, has full access */
    public static function fullAccess(): void
    {
        if (!AdminAccess::isFull(CliActor::current())) {
            throw new PermissionDenied();
        }
    }
}
