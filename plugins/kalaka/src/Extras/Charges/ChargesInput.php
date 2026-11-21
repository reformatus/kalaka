<?php

declare(strict_types=1);

namespace Kalaka\Extras\Charges;

use Kalaka\Events\Api\Event;
use Kalaka\Extras\Api\Charge;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Money\Money;

/**
 * Reads extra charges as sent (`kalaka/set-extra-charges`'s `charges`), for the preview and the save alike:
 * keys unique, amounts in the event's currency, and each named field acceptable to `ChargeField`. It reads
 * the event's participant fields (no post).
 */
final class ChargesInput
{
    /** The JSON schema of one charge as sent. */
    public const ITEM_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,63}$'],
            'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
            'amount' => ['type' => 'string', 'pattern' => '^[0-9]{1,18}(\\.[0-9]{1,8})?$'],
            'field' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,63}$'],
        ],
        'required' => ['key', 'label', 'amount', 'field'],
        'additionalProperties' => false,
    ];

    public function __construct(private readonly ChargeField $field = new ChargeField())
    {
    }

    /**
     * @param array<array-key, mixed> $items the charges as sent, in order, each valid for `ITEM_SCHEMA`
     * @return list<Charge>
     * @throws InvalidInput a key given twice, or a field that is not an active consent or whole-number field
     */
    public function charges(Event $event, array $items): array
    {
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
            $field = (string) $item['field'];
            $this->field->check($event->id, $field);
            $parsed[] = new Charge(
                $key,
                (string) $item['label'],
                Money::of((string) $item['amount'], $event->currency),
                $field,
            );
        }

        return $parsed;
    }
}
