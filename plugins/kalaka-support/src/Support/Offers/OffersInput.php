<?php

declare(strict_types=1);

namespace Kalaka\Support\Offers;

use Kalaka\Events\Api\Event;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\Support\Api\Offer;
use Kalaka\Support\Directory\NodeRepository;

/**
 * Reads support offers as sent (`kalaka/set-support-offers`'s `offers`), for the preview and the save alike: keys
 * unique, amounts positive and in the event's currency, a node that exists in the directory. An offer's key is
 * the key of its consent field and an item key of the `support` component (`support:<key>`), which `FeeComposer`
 * holds to 40 characters, so a key is at most 40.
 */
final class OffersInput
{
    /** The longest an offer key can be: an item key of the `support` component. [UNAPPROVED N1] */
    public const MAX_KEY_LENGTH = 40;

    /** The most offers an event has. */
    public const MAX_OFFERS = 50;

    /** The JSON schema of one offer as sent. */
    public const ITEM_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,' . (self::MAX_KEY_LENGTH - 1) . '}$'],
            'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
            'node' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
            'amount' => ['type' => 'string', 'pattern' => '^[0-9]{1,18}(\\.[0-9]{1,8})?$'],
            'request' => ['type' => 'boolean'],
            'limit' => ['type' => ['integer', 'null'], 'minimum' => 1],
        ],
        'required' => ['key', 'label', 'node', 'amount', 'request', 'limit'],
        'additionalProperties' => false,
    ];

    public function __construct(
        private readonly NodeRepository $nodes = new NodeRepository(),
        private readonly ParticipantFields $fields = new ParticipantFields(),
    ) {
    }

    /**
     * @param array<array-key, mixed> $items the offers as sent, in order, each valid for `ITEM_SCHEMA`
     * @return list<Offer>
     * @throws InvalidInput a key given twice, an offer's key that is another offer's answer field, a key a form or
     *     staff field of the event already holds, an amount that is not positive, or a node the directory does
     *     not have
     */
    public function offers(Event $event, array $items): array
    {
        $parsed = [];
        $seen = [];
        $held = $this->heldKeys($event->id);
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidInput();
            }
            $key = (string) $item['key'];
            $answerKey = $key . SetSupportOffers::ANSWER_SUFFIX;
            if (
                isset($seen[$key]) || isset($seen[$answerKey])
                || self::clashes($held, $key, SetSupportOffers::CONSENT_TYPE)
                || self::clashes($held, $answerKey, SetSupportOffers::CHOICE_TYPE)
            ) {
                throw new InvalidInput();
            }
            $seen[$key] = true;
            $seen[$answerKey] = true;
            $amount = Money::of((string) $item['amount'], $event->currency);
            if (!$amount->isPositive() || $this->nodes->findByExternalId((string) $item['node']) === null) {
                throw new InvalidInput();
            }
            $parsed[] = new Offer(
                $key,
                (string) $item['label'],
                (string) $item['node'],
                $amount,
                (bool) $item['request'],
                $item['limit'] === null ? null : (int) $item['limit'],
            );
        }

        return $parsed;
    }

    /**
     * The event's fields by key, with their source and type: what an offer's declaration must not take over.
     *
     * @return array<string, ParticipantField>
     */
    private function heldKeys(int $eventId): array
    {
        $held = [];
        foreach ($this->fields->of($eventId, true) as $field) {
            $held[$field->key] = $field;
        }

        return $held;
    }

    /**
     * Whether a field already holds the key other than as the extension's own declaration of the type: a form's,
     * a staff's or a system field, or an extension's of another type.
     *
     * @param array<string, ParticipantField> $held
     */
    private static function clashes(array $held, string $key, string $type): bool
    {
        $field = $held[$key] ?? null;

        return $field !== null && ($field->source !== ParticipantField::SOURCE_EXTENSION || $field->type !== $type);
    }
}
