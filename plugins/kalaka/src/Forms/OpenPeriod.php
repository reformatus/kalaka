<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Events\Api\Event;
use Kalaka\Forms\Api\Form;

/**
 * An additional form's open period (events-and-forms.md, "Forms and the registration slot", "Date
 * references"): each end a date reference, stored in JSON in the form's settings (`kalaka_opens`,
 * `kalaka_closes`):
 *
 * - a fixed date: `{"kind": "fixed", "at": "2026-12-01 08:00:00"}`, the instant in UTC (an admin enters a
 *   day and a time in the site time zone, stored in UTC, as for the event's points);
 * - an event point: `{"kind": "point", "point": "registration_opens"}` (also `registration_closes`,
 *   `start`, `end`), or `{"kind": "point", "point": "custom", "id": 3}` for an admin's own point.
 *
 * A date type is not a reference here yet: no extension contributes one, and the core payment due is a
 * registration's, not a form's (default; re-asked when the first date type lands). The form is open from
 * its opening instant (included) up to its closing instant (excluded); an end that is not set does not
 * limit it, so with neither set it is open while its event is published (default, S60). An end that is
 * set but does not resolve (malformed, an unknown kind, a point the event has not set) closes the form.
 * Pure: it reads the form and the event given, no clock and no database.
 */
final class OpenPeriod
{
    /** The core points a reference may name, by name. */
    private const POINTS = ['registration_opens', 'registration_closes', 'start', 'end'];

    /** Whether the additional form is open at `$now`. */
    public static function contains(Form $form, Event $event, \DateTimeImmutable $now): bool
    {
        $opens = self::resolve($form->opens, $event);
        $closes = self::resolve($form->closes, $event);
        if ($opens === false || $closes === false) {
            return false;
        }

        return ($opens === null || $opens <= $now) && ($closes === null || $now < $closes);
    }

    /**
     * One end: null when it is not set, false when it is set but does not resolve.
     */
    public static function resolve(?string $reference, Event $event): \DateTimeImmutable|false|null
    {
        if ($reference === null || trim($reference) === '') {
            return null;
        }
        $decoded = json_decode($reference, true);
        if (!is_array($decoded)) {
            return false;
        }

        return match ($decoded['kind'] ?? null) {
            'fixed' => self::fixed($decoded['at'] ?? null),
            'point' => self::point($decoded, $event) ?? false,
            default => false,
        };
    }

    private static function fixed(mixed $at): \DateTimeImmutable|false
    {
        if (!is_string($at) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $at) !== 1) {
            return false;
        }
        $instant = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $at, new \DateTimeZone('UTC'));

        return $instant !== false && $instant->format('Y-m-d H:i:s') === $at ? $instant : false;
    }

    /** @param array<array-key, mixed> $reference */
    private static function point(array $reference, Event $event): ?\DateTimeImmutable
    {
        $name = $reference['point'] ?? null;
        if ($name === 'custom') {
            $id = $reference['id'] ?? null;
            foreach ($event->customPoints as $point) {
                if (is_int($id) && $point->id === $id) {
                    return $point->at;
                }
            }

            return null;
        }
        if (!in_array($name, self::POINTS, true)) {
            return null;
        }

        return match ($name) {
            'registration_opens' => $event->registrationOpens,
            'registration_closes' => $event->registrationCloses,
            'start' => $event->start,
            'end' => $event->end,
        };
    }
}
