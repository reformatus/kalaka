<?php

declare(strict_types=1);

namespace Kalaka\Mail;

/**
 * The hourly budget and each run's share of what is left. The hour is the UTC clock hour with twelve
 * 5-minute slots; a run may claim `ceil(L / S) - C`: L the budget less the hour's claims before the current
 * slot, S the slots left counting this one, C the claims already made in this slot. mail.md, "Sending".
 */
final class Throttle
{
    private const SLOT_SECONDS = 300;

    private const SLOTS = 12;

    public function __construct(private readonly DeliveryQuery $query = new DeliveryQuery())
    {
    }

    /** The claims a run starting at `$now` may make; 0 or less when the slot's share is used up. */
    public function share(\DateTimeImmutable $now, int $hourlyBudget): int
    {
        $slotStart = self::slotStart($now);
        $hourStart = $slotStart->setTime((int) $slotStart->format('G'), 0);
        $counts = $this->query->claimCounts($hourStart, $slotStart, self::nextSlotStart($now));

        $left = $hourlyBudget - $counts['before'];
        $slotsLeft = self::SLOTS - intdiv((int) $slotStart->format('i'), 5);

        return (int) ceil($left / $slotsLeft) - $counts['current'];
    }

    public static function nextSlotStart(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return self::slotStart($now)->modify('+' . self::SLOT_SECONDS . ' seconds');
    }

    private static function slotStart(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $utc = $now->setTimezone(new \DateTimeZone('UTC'));

        return $utc->setTime((int) $utc->format('G'), intdiv((int) $utc->format('i'), 5) * 5);
    }
}
