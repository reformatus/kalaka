<?php

declare(strict_types=1);

namespace Kalaka\Support\Offers;

use Kalaka\Events\Api\Event;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Money\Money;
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
    /** The longest an offer key can be: an item key of the `support` component. [UNAPPROVED N91] */
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
            // A limit of 0 is a full offer. [UNAPPROVED N359]
            'limit' => ['type' => ['integer', 'null'], 'minimum' => 0],
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
     * @throws InvalidInput a key given twice, an offer's key that is another offer's answer field, an amount that is
     *     not positive, or a node the directory does not have
     * @throws RuleViolation a key the event's participant fields hold that this module may
     *     not declare (`ParticipantFields::checkDeclarable()`: a staff or system field, another type, another
     *     declarer); a form's field of the same type is adopted
     */
    public function offers(Event $event, array $items): array
    {
        $parsed = [];
        $seen = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidInput();
            }
            $key = (string) $item['key'];
            // The answer key is held only by an offer that must be requested: it declares no answer field otherwise.
            $request = (bool) $item['request'];
            $answerKey = $key . SetSupportOffers::ANSWER_SUFFIX;
            if (isset($seen[$key]) || ($request && isset($seen[$answerKey]))) {
                throw new InvalidInput();
            }
            // One rule for every extension: a key a form's field of the type holds is adopted, any other holder
            // (staff, system, another type, another declarer) refuses with a RuleViolation.
            $this->fields->checkDeclarable(
                $event->id,
                $key,
                SetSupportOffers::CONSENT_TYPE,
                SetSupportOffers::DECLARER
            );
            if ($request) {
                $this->fields->checkDeclarable(
                    $event->id,
                    $answerKey,
                    SetSupportOffers::CHOICE_TYPE,
                    SetSupportOffers::DECLARER
                );
            }
            $seen[$key] = true;
            if ($request) {
                $seen[$answerKey] = true;
            }
            $amount = Money::of((string) $item['amount'], $event->currency);
            if (!$amount->isPositive() || $this->nodes->findByExternalId((string) $item['node']) === null) {
                throw new InvalidInput();
            }
            $parsed[] = new Offer(
                $key,
                (string) $item['label'],
                (string) $item['node'],
                $amount,
                $request,
                $item['limit'] === null ? null : (int) $item['limit'],
            );
        }

        return $parsed;
    }
}
