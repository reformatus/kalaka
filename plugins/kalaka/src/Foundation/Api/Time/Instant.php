<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Time;

/**
 * foundation.md, "Time": inside the plugin an instant is a `\DateTimeImmutable`. This is the one place
 * that turns it into the UTC `datetime(6)` text of a column or of stored JSON, and back.
 */
final class Instant
{
    public const STORAGE_FORMAT = 'Y-m-d H:i:s.u';

    public static function toStorage(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format(self::STORAGE_FORMAT);
    }

    public static function fromStorage(string $stored): \DateTimeImmutable
    {
        return new \DateTimeImmutable($stored, new \DateTimeZone('UTC'));
    }

    /**
     * A wall time `YYYY-MM-DD HH:MM` in the site time zone, as the UTC instant. A time the zone skips
     * is refused; a time it has twice is the earlier instant.
     *
     * @return ?\DateTimeImmutable null when the text is not a valid, existing local time
     */
    public static function fromLocal(string $local): ?\DateTimeImmutable
    {
        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}\z/', $local) !== 1) {
            return null;
        }
        $zone = wp_timezone();
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $local, $zone);
        // A skipped hour is moved forward by PHP, so the round trip no longer matches.
        if ($parsed === false || $parsed->format('Y-m-d H:i') !== $local) {
            return null;
        }

        // PHP picks the later instant of a repeated hour; the earlier one is an hour before when the
        // zone is on that side of the switch.
        $earlier = $parsed->setTimestamp($parsed->getTimestamp() - 3600);
        if ($earlier->format('Y-m-d H:i') === $local) {
            $parsed = $earlier;
        }

        return $parsed->setTimezone(new \DateTimeZone('UTC'));
    }

    /** 00:00 of a `Y-m-d` day in the site time zone, as a UTC instant; null when it is not a calendar day. */
    public static function startOfDay(string $day): ?\DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        $check = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, $utc);
        if ($check === false || $check->format('Y-m-d') !== $day) {
            return null;
        }

        return (new \DateTimeImmutable($day . ' 00:00:00', wp_timezone()))->setTimezone($utc);
    }
}
