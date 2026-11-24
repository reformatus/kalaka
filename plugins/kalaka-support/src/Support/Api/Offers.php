<?php

declare(strict_types=1);

namespace Kalaka\Support\Api;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Registrations\Api\Seats;
use Kalaka\Support\Directory\NodeRepository;
use Kalaka\Support\Offers\OfferChangeGuard;
use Kalaka\Support\Offers\OfferRecord;
use Kalaka\Support\Offers\OfferRepository;
use Kalaka\Support\Offers\OfferSeatPool;
use Kalaka\Support\Offers\OffersInput;

/**
 * Support offers for the settings screen (S72) and the form: an event's offers, proposed ones to hand to
 * `Pricing\Api\MoneySettings` (preview, confirm, apply; money.md, "Money settings apply"), and the seats left of
 * the limited ones. Plain reads, outside any use case.
 */
final class Offers
{
    public function __construct(
        private readonly OfferRepository $offers = new OfferRepository(),
        private readonly Events $events = new Events(),
        private readonly OffersInput $input = new OffersInput(),
        private readonly Seats $seats = new Seats(),
        private readonly NodeRepository $nodes = new NodeRepository(),
        private readonly OfferChangeGuard $guard = new OfferChangeGuard(),
    ) {
    }

    /**
     * The event's offers in order; none when it has none.
     *
     * @return list<Offer>
     */
    public function offers(int $eventId): array
    {
        return array_map(
            fn (OfferRecord $record): Offer => $record->offer(
                $this->nodes->find($record->nodeId)->externalId ?? ''
            ),
            $this->offers->forEvent($eventId)
        );
    }

    /**
     * The seats left in each limited offer's pool, by the form-condition variable that reads it
     * (`seats_left.<offer key>.1`: the offer's consent field and its one option, a ticked box). An offer with no
     * limit has no variable. A plain read, never below zero.
     *
     * @return array<string, int>
     */
    public function seatsLeft(int $eventId): array
    {
        $left = [];
        foreach ($this->offers->forEvent($eventId) as $record) {
            if ($record->limit === null) {
                continue;
            }
            $left['seats_left.' . $record->key . '.1'] = $this->seats->left(
                new OfferSeatPool($eventId, $record->key, $this->offers)
            ) ?? 0;
        }

        return $left;
    }

    /**
     * Proposed offers, the whole list in order (an empty list removes them), each
     * `{key, label, node, amount, request, limit}`: `node` the directory node's external ID, `amount` a decimal
     * string in the event's currency, `request` whether staff or a partner must approve it, `limit` the most
     * people or null.
     *
     * @param list<array<string, mixed>> $offers
     * @throws NotFound when there is no such event
     * @throws InvalidInput when the offers are not valid; `kalaka/set-support-offers` checks its schema again
     * @throws RuleViolation when the save would refuse (`OfferChangeGuard`): the preview and the apply refuse
     *     before anything is saved
     */
    public function propose(int $eventId, array $offers): ProposedOffers
    {
        $event = $this->events->find($eventId) ?? throw new NotFound();
        foreach ($offers as $offer) {
            if (rest_validate_value_from_schema($offer, OffersInput::ITEM_SCHEMA) !== true) {
                throw new InvalidInput();
            }
        }
        $input = ['event_id' => $eventId, 'currency' => $event->currency->value, 'offers' => $offers];

        $proposed = $this->input->offers($event, $offers);
        $this->guard->checkAgainstStored($eventId, $proposed);

        return new ProposedOffers($proposed, $input);
    }
}
