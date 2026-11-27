<?php

declare(strict_types=1);

namespace Kalaka\Events\Api;

use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\KalakaException;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Events\UpdateEvent;

/**
 * [UNAPPROVED N1190] The seam an admin page saves an event's start and end through, so it does not depend on
 * Events' use case: runs `kalaka/update-event` through the runner as the current user. The use case keeps its
 * own permission check (full access); this adds none, so exactly who may update an event may save its times.
 *
 * The use case replaces the whole record, so everything but the two times is passed from the event given:
 * pass the event as it was read for the page, with the version the form was built from.
 */
final class EventTimes
{
    public function __construct(private readonly ?Runner $runner = null)
    {
    }

    /**
     * @param ?string $startsLocal the start as a local `YYYY-MM-DD HH:MM` in the site time zone, or null for none
     * @param ?string $endsLocal the end, in the same form
     * @param int $version the event version the form was built from
     * @return Event the event as saved
     * @throws \Kalaka\Foundation\Api\Error\PermissionDenied the user lacks full access
     * @throws \Kalaka\Foundation\Api\Error\VersionConflict the event changed since that version
     * @throws \Kalaka\Foundation\Api\Error\InvalidInput a malformed or impossible time, or the end before the start
     * @throws \Kalaka\Foundation\Api\Error\RuleViolation the event is archived, or a published one lacks a time
     * @throws NotFound the event is gone
     * @throws KalakaException anything else the run throws
     */
    public function save(Event $event, ?string $startsLocal, ?string $endsLocal, int $version): Event
    {
        $input = [
            'id' => $event->id,
            'version' => $version,
            'name' => $event->name,
            'capacity' => $event->capacity,
            'registration_opens_at' => self::local($event->registrationOpens),
            'registration_closes_at' => self::local($event->registrationCloses),
            'starts_at' => $startsLocal,
            'ends_at' => $endsLocal,
            'points' => array_map(
                static fn (EventPoint $point): array => [
                    'id' => $point->id,
                    'name' => $point->name,
                    'at' => self::local($point->at) ?? '',
                ],
                $event->customPoints
            ),
        ];
        ($this->runner ?? Runner::current())->run(new UpdateEvent(), $input, new UserActor(get_current_user_id()));

        return (new Events())->find($event->id) ?? throw new NotFound();
    }

    private static function local(?\DateTimeImmutable $instant): ?string
    {
        return $instant?->setTimezone(wp_timezone())->format('Y-m-d H:i');
    }
}
