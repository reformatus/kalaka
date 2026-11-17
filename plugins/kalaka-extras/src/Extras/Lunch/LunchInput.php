<?php

declare(strict_types=1);

namespace Kalaka\Extras\Lunch;

use Kalaka\Events\Api\Event;
use Kalaka\Extras\Api\LunchItem;
use Kalaka\Forms\Api\Availability;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Registrations\Api\Registrations;

/**
 * Reads lunch items as sent (`kalaka/set-lunch`'s `items`), for the preview and the save alike: keys unique,
 * amounts in the event's currency, and each condition checked against the keys a registration's facts
 * declare for the event. It reads the event's participant fields (no post).
 */
final class LunchInput
{
    /** The JSON schema of one item as sent. */
    public const ITEM_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,63}$'],
            'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
            'amount' => ['type' => 'string', 'pattern' => '^[0-9]{1,18}(\\.[0-9]{1,8})?$'],
            'condition' => ['type' => ['object', 'null']],
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
     * @param array<array-key, mixed> $items the items as sent, in order, each valid for `ITEM_SCHEMA`
     * @return list<LunchItem>
     * @throws InvalidInput a key given twice, or a condition that is invalid or reads an undeclared key
     */
    public function items(Event $event, array $items): array
    {
        $declared = null;
        $parsed = [];
        $seen = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidInput();
            }
            $key = (string) $item['key'];
            if (isset($seen[$key])) {
                throw new InvalidInput();
            }
            $seen[$key] = true;

            $condition = isset($item['condition']) && is_array($item['condition']) ? $item['condition'] : null;
            if ($condition !== null) {
                $declared ??= $this->registrations->declaredFacts($event);
                try {
                    $this->availability->check($condition, $declared);
                } catch (SystemFailure) {
                    throw new InvalidInput();
                }
            }

            $parsed[] = new LunchItem(
                $key,
                (string) $item['label'],
                Money::of((string) $item['amount'], $event->currency),
                $condition,
            );
        }

        return $parsed;
    }
}
