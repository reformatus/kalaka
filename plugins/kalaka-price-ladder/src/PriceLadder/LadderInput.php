<?php

declare(strict_types=1);

namespace Kalaka\PriceLadder;

use Kalaka\Events\Api\Event;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\PriceLadder\Api\Tier;

/**
 * Reads a ladder as sent (`kalaka/set-price-ladder`'s `tiers`) into tiers, for the preview and the save
 * alike: keys unique and amounts in the event's currency. A tier's `limit` is optional: the most people (0 or
 * more), or none (absent or null).
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
            'limit' => ['type' => ['integer', 'null'], 'minimum' => 0],
        ],
        'required' => ['key', 'label', 'amount'],
        'additionalProperties' => false,
    ];

    /**
     * @param array<array-key, mixed> $tiers the tiers as sent, in ladder order, each valid for `TIER_SCHEMA`
     * @return list<Tier>
     * @throws InvalidInput a key given twice
     */
    public function tiers(Event $event, array $tiers): array
    {
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
            $limit = $tier['limit'] ?? null;
            $parsed[] = new Tier(
                $key,
                (string) $tier['label'],
                Money::of((string) $tier['amount'], $event->currency),
                $limit === null ? null : (int) $limit,
            );
        }

        return $parsed;
    }
}
