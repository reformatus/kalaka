<?php

declare(strict_types=1);

namespace Kalaka\PriceLadder;

use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\FieldKind;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\PriceLadder\Api\Tier;

/**
 * Saves an event's price ladder, the whole list of tiers in ladder order: a step of a money settings apply
 * (`Pricing\Api\MoneySettings::apply()` with `Api\PriceLadder::propose()`), which then re-evaluates every
 * open registration. Tiers left out are deleted; an empty list removes the ladder. Saving alone touches no
 * registration. Site-level, like core's fixed price: full access only. The currency must be the event's.
 *
 * It declares the event's participant field `price_tier` in the same transaction (the owner's ruling
 * 2026-11-19): source `extension`, form-writable, a choice whose options are the tiers, each with the tier's
 * key as its value and the tier's label as its label. A new tier adds an option, a removed tier's option is
 * retired with its label kept, and a renamed tier renames its option (the declarer's text). An event that
 * never had a ladder gets no field from an empty one (#200 N13, approved).
 *
 * It refuses to remove a tier an open (registered or waitlisted) registration has chosen, and to declare
 * `price_tier` over another declarer's field (`LadderSaveGuard`, which the preview runs too). A tier's
 * limit may be given, changed or removed at any time: every chooser of a tier holds a seat row, limited or not.
 */
final class SetPriceLadder implements AdminUseCase
{
    public function __construct(
        private readonly TierRepository $tiers = new TierRepository(),
        private readonly Events $events = new Events(),
        private readonly LadderInput $ladder = new LadderInput(),
        private readonly ParticipantFields $fields = new ParticipantFields(),
        private readonly LadderSaveGuard $guard = new LadderSaveGuard(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/set-price-ladder';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3],
                'tiers' => ['type' => 'array', 'maxItems' => 50, 'items' => LadderInput::TIER_SCHEMA],
            ],
            'required' => ['event_id', 'currency', 'tiers'],
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
        $tiers = is_array($input['tiers']) ? $input['tiers'] : [];
        $proposed = $this->ladder->tiers($event, $tiers);

        $stored = [];
        foreach ($this->tiers->forEvent($eventId, $context) as $record) {
            $stored[$record->key] = $record;
        }
        $this->guard->check($eventId, $stored, $proposed);

        foreach ($proposed as $position => $tier) {
            $record = $stored[$tier->key] ?? null;
            unset($stored[$tier->key]);
            if ($record === null) {
                $record = new TierRecord(
                    $eventId,
                    $tier->key,
                    $position,
                    $tier->label,
                    $tier->amount,
                    limit: $tier->limit,
                );
                $this->tiers->upsert($record, $context);
                continue;
            }
            $record->position = $position;
            $record->label = $tier->label;
            $record->amount = $tier->amount;
            $record->limit = $tier->limit;
            $this->tiers->upsert($record, $context);
        }
        $hadLadder = $stored !== [] || $proposed !== [];
        foreach ($stored as $record) {
            $this->tiers->delete($record, $context);
        }
        if ($hadLadder) {
            $this->fields->declareExtensionField(
                $context,
                $eventId,
                PriceLadderComponent::FIELD,
                FieldKind::CHOICE,
                PriceLadderComponent::FIELD_TITLE,
                array_map(static fn (Tier $tier): array => ['value' => $tier->key, 'label' => $tier->label], $proposed),
                true,
                PriceLadderComponent::DECLARER,
                null,
                true,
            );
        }

        return null;
    }
}
