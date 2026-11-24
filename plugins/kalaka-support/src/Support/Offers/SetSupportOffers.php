<?php

declare(strict_types=1);

namespace Kalaka\Support\Offers;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Support\Api\Offer;
use Kalaka\Support\Directory\NodeRepository;

/**
 * Saves an event's support offers, the whole list in order: a step of a money settings apply
 * (`Pricing\Api\MoneySettings::apply()` with `Api\Offers::propose()`), which then re-evaluates every open
 * registration. Offers left out are deleted; an empty list removes them. Saving alone touches no registration.
 * Site-level, like core's fixed price: full access only. The currency must be the event's.
 *
 * It refuses to remove an offer, or to give an unlimited one a limit, while an open (registered or waitlisted)
 * registration has selected it: a seat or a line would be taken from somebody by a settings change. A lower limit
 * is allowed, and only makes the pool stand over: nobody loses a seat.
 *
 * In the same transaction it declares the participant fields of each offer (the owner's rulings 2026-11-23, topic
 * 14: the code defines the field, the form's field links to it by key): the selection, a consent whose key is the
 * offer's key, form-writable and titled with the offer's label; and, for an offer that must be requested, its
 * answer `<key>_answer`, a choice of `approved` and `refused`, written only by the offer's request source (the
 * answer act). A removed offer's fields stay declared: core has no way to retire a field, and the form decides
 * whether the field shows. [UNAPPROVED N1, N2]
 */
final class SetSupportOffers implements AdminUseCase
{
    /** The block type of a consent field and of a choice field, core's. */
    public const CONSENT_TYPE = 'kalaka/consent-field';
    public const CHOICE_TYPE = 'kalaka/choice-field';

    /** The answer field's key, after the offer's key. */
    public const ANSWER_SUFFIX = '_answer';

    /** The title of an offer's answer field, after the offer's label. [UNAPPROVED T1] */
    public const ANSWER_TITLE = '%s: válasz';

    /** The labels of the answer's two options. [UNAPPROVED T2, T3] */
    public const APPROVED_LABEL = 'Jóváhagyva';
    public const REFUSED_LABEL = 'Elutasítva';

    public function __construct(
        private readonly OfferRepository $offers = new OfferRepository(),
        private readonly Events $events = new Events(),
        private readonly OffersInput $input = new OffersInput(),
        private readonly ParticipantFields $fields = new ParticipantFields(),
        private readonly Registrations $registrations = new Registrations(),
        private readonly NodeRepository $nodes = new NodeRepository(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/set-support-offers';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3],
                'offers' => [
                    'type' => 'array',
                    'maxItems' => OffersInput::MAX_OFFERS,
                    'items' => OffersInput::ITEM_SCHEMA,
                ],
            ],
            'required' => ['event_id', 'currency', 'offers'],
            'additionalProperties' => false,
        ];
    }

    public function action(): ?string
    {
        return null;
    }

    public function event(array $input): ?int
    {
        return null;
    }

    public function targets(array $input): array
    {
        return [];
    }

    public function execute(array $input, Context $context): null
    {
        $eventId = (int) $input['event_id'];
        $event = $this->events->find($eventId) ?? throw new NotFound();
        if (Currency::tryFrom((string) $input['currency']) !== $event->currency) {
            throw new InvalidInput();
        }
        $proposed = $this->input->offers($event, is_array($input['offers']) ? $input['offers'] : []);

        $stored = [];
        foreach ($this->offers->forEvent($eventId, $context) as $record) {
            $stored[$record->key] = $record;
        }
        $this->refuseWhatWouldStripASeat($eventId, $stored, $proposed);

        foreach ($proposed as $position => $offer) {
            $nodeId = $this->nodes->findByExternalId($offer->node)->id ?? throw new InvalidInput();
            $record = $stored[$offer->key] ?? null;
            unset($stored[$offer->key]);
            if ($record === null) {
                $record = new OfferRecord(
                    $eventId,
                    $offer->key,
                    $position,
                    $offer->label,
                    $nodeId,
                    $offer->amount,
                    $offer->needsRequest,
                    $offer->limit,
                );
            } else {
                $record->position = $position;
                $record->label = $offer->label;
                $record->nodeId = $nodeId;
                $record->amount = $offer->amount;
                $record->needsRequest = $offer->needsRequest;
                $record->limit = $offer->limit;
            }
            $this->offers->upsert($record, $context);
        }
        foreach ($stored as $record) {
            $this->offers->delete($record, $context);
        }
        // The offers' rows (quota level) first, then the fields (any other level): the lock order.
        foreach ($proposed as $offer) {
            $this->declareFields($context, $eventId, $offer);
        }

        return null;
    }

    /** The two fields of an offer: its selection and, when it must be requested, its answer. */
    private function declareFields(Context $context, int $eventId, Offer $offer): void
    {
        $this->fields->declareExtensionField(
            $context,
            $eventId,
            $offer->key,
            self::CONSENT_TYPE,
            $offer->label,
            null,
            true,
        );
        if ($offer->needsRequest) {
            $this->fields->declareExtensionField(
                $context,
                $eventId,
                $offer->key . self::ANSWER_SUFFIX,
                self::CHOICE_TYPE,
                sprintf(self::ANSWER_TITLE, $offer->label),
                [
                    ['value' => 'approved', 'label' => self::APPROVED_LABEL],
                    ['value' => 'refused', 'label' => self::REFUSED_LABEL],
                ],
                false,
            );
        }
    }

    /**
     * @param array<string, OfferRecord> $stored by key
     * @param list<Offer> $proposed
     * @throws RuleViolation when a removed offer, or one given a limit, is selected on an open registration
     */
    private function refuseWhatWouldStripASeat(int $eventId, array $stored, array $proposed): void
    {
        $kept = [];
        foreach ($proposed as $offer) {
            $kept[$offer->key] = $offer;
        }
        $watched = [];
        foreach ($stored as $key => $record) {
            $next = $kept[$key] ?? null;
            if ($next === null || ($record->limit === null && $next->limit !== null)) {
                $watched[] = $key;
            }
        }
        if ($watched === []) {
            return;
        }
        foreach ($this->registrations->openOf($eventId) as $registration) {
            $values = $this->registrations->values($registration);
            foreach ($watched as $key) {
                if (($values[$key] ?? '') === '1') {
                    throw new RuleViolation();
                }
            }
        }
    }
}
