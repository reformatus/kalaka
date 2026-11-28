<?php

declare(strict_types=1);

namespace Kalaka\Ledger;

use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Ledger\Api\PaymentMethod;

/** What the manual entry use cases share: who recorded, the amount's decimals and the schema of the entry's fields. */
final class ManualEntryParts
{
    /** The longest caller key: the stored key is prefixed (`manual:`, `manual_fee:`) and may take a suffix. */
    public const KEY_PATTERN = '^[a-z0-9_:.-]{1,100}\\z';

    /** @return array<string, array<string, mixed>> the schema of a payment's or refund's own fields */
    public static function entrySchema(): array
    {
        return [
            'method' => ['type' => 'string', 'enum' => array_map(
                static fn (PaymentMethod $method): string => $method->value,
                PaymentMethod::cases()
            )],
            'value_date' => ['type' => 'string', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}\\z'],
            'note' => ['type' => 'string', 'maxLength' => 500],
        ];
    }

    /** Who recorded it, as the line's source: the WordPress user, or the system for a run without one. */
    public static function sourceOf(Actor $actor): Subject
    {
        $userId = self::userIdOf($actor);

        if ($userId === null) {
            return new Subject('system', null, 'system');
        }

        return new Subject('wp_user', null, (string) $userId);
    }

    /** The WordPress user that acts, or null for a run without one. */
    public static function userIdOf(Actor $actor): ?int
    {
        return $actor instanceof UserActor || $actor instanceof CliActor ? $actor->userId : null;
    }

    /** Whether the value date is a real day that is not after today in the site time zone. */
    public static function isPastDay(string $valueDate, \DateTimeImmutable $now): bool
    {
        return Instant::startOfDay($valueDate) !== null
            && $valueDate <= $now->setTimezone(wp_timezone())->format('Y-m-d');
    }

    public static function decimals(string $amount): int
    {
        $point = strpos($amount, '.');

        return $point === false ? 0 : strlen(rtrim(substr($amount, $point + 1), '0'));
    }
}
