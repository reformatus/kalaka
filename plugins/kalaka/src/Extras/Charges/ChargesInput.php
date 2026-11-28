<?php

declare(strict_types=1);

namespace Kalaka\Extras\Charges;

use Kalaka\Events\Api\Event;
use Kalaka\Extras\Api\Charge;
use Kalaka\Extras\Donation\DonationComponent;
use Kalaka\Forms\Api\FieldKind;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Money\Money;

/**
 * Reads extra charges as sent (`kalaka/set-extra-charges`'s `charges`), for the preview and the save alike:
 * keys unique and not the donation's, amounts in the event's currency, a type (`consent` or `number`, consent
 * when none is given), and the field each key already has acceptable to `ChargeField`. It reads the event's
 * participant fields (no post).
 */
final class ChargesInput
{
    /**
     * The longest a charge key can be: it is an item key of the Extras component (`extras:<key>`),
     * which `FeeComposer` holds to 40 characters.
     */
    public const MAX_KEY_LENGTH = 40;

    /** The JSON schema of one charge as sent. */
    public const ITEM_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,' . (self::MAX_KEY_LENGTH - 1) . '}$'],
            'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
            'amount' => ['type' => 'string', 'pattern' => '^[0-9]{1,18}(\\.[0-9]{1,8})?$'],
            'type' => ['type' => 'string', 'enum' => [Charge::CONSENT, Charge::NUMBER]],
        ],
        'required' => ['key', 'label', 'amount'],
        'additionalProperties' => false,
    ];

    public function __construct(private readonly ChargeField $field = new ChargeField())
    {
    }

    /**
     * @param array<array-key, mixed> $items the charges as sent, in order, each valid for `ITEM_SCHEMA`
     * @return list<Charge>
     * @throws InvalidInput a key given twice or the donation's, or a number field with a key that allows decimals
     * @throws RuleViolation a key a field of another source holds, or one declared with another type; also the
     *     donation's field when a form field or another type holds `adomany`, since every save declares it
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
            if (isset($seen[$key]) || $key === DonationComponent::KEY) {
                throw new InvalidInput();
            }
            $seen[$key] = true;
            $type = (string) ($item['type'] ?? Charge::CONSENT);
            $this->field->check($event->id, $key, ChargeField::block($type));
            $parsed[] = new Charge(
                $key,
                (string) $item['label'],
                Money::of((string) $item['amount'], $event->currency),
                $type,
            );
        }
        $this->field->check($event->id, DonationComponent::KEY, FieldKind::NUMBER, false);

        return $parsed;
    }
}
