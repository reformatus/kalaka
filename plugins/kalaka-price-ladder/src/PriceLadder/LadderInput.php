<?php

declare(strict_types=1);

namespace Kalaka\PriceLadder;

use Kalaka\Events\Api\Event;
use Kalaka\Forms\Api\Availability;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\PriceLadder\Api\Tier;
use Kalaka\Registrations\Api\Registrations;

/**
 * Reads a ladder as sent (`kalaka/set-price-ladder`'s `tiers`) into tiers, for the preview and the save
 * alike: keys unique, amounts in the event's currency, each condition checked against the keys a
 * registration's facts declare for the event, and a deadline day turned into the instant its day ends in the
 * site time zone. It reads the event's participant fields (no post).
 */
final class LadderInput
{
    /** The JSON schema of one tier as sent. */
    public const TIER_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,63}$'],
            'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
            'amount' => ['type' => 'string', 'pattern' => '^[0-9]{1,18}(\\.[0-9]{1,8})?$'],
            'condition' => ['type' => ['object', 'null']],
            'until' => ['type' => ['string', 'null'], 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'],
        ],
        'required' => ['key', 'label', 'amount'],
        'additionalProperties' => false,
    ];

    public function __construct(
        private readonly Registrations $registrations = new Registrations(),
        private readonly Availability $availability = new Availability(),
    ) {
    }

    /**
     * @param array<array-key, mixed> $tiers the tiers as sent, in ladder order, each valid for `TIER_SCHEMA`
     * @return list<Tier>
     * @throws InvalidInput a key given twice, a condition that is invalid or reads an undeclared key, a
     *     deadline that is not a calendar day
     */
    public function tiers(Event $event, array $tiers): array
    {
        $declared = null;
        $parsed = [];
        $seen = [];
        foreach ($tiers as $tier) {
            if (!is_array($tier)) {
                throw new InvalidInput();
            }
            $key = (string) $tier['key'];
            if (isset($seen[$key])) {
                throw new InvalidInput();
            }
            $seen[$key] = true;

            $condition = isset($tier['condition']) && is_array($tier['condition']) ? $tier['condition'] : null;
            if ($condition !== null) {
                $declared ??= $this->registrations->declaredFacts($event);
                try {
                    $this->availability->check($condition, $declared);
                } catch (SystemFailure) {
                    throw new InvalidInput();
                }
            }

            $parsed[] = new Tier(
                $key,
                (string) $tier['label'],
                Money::of((string) $tier['amount'], $event->currency),
                $condition,
                isset($tier['until']) ? self::endOf((string) $tier['until']) : null,
            );
        }

        return $parsed;
    }

    /** The instant a day ends in the site time zone: the start of the next day. */
    private static function endOf(string $day): \DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, $utc);
        if ($parsed === false || $parsed->format('Y-m-d') !== $day) {
            throw new InvalidInput();
        }

        return Instant::startOfDay($parsed->modify('+1 day')->format('Y-m-d')) ?? throw new InvalidInput();
    }
}
