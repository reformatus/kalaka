<?php

declare(strict_types=1);

namespace Kalaka\PriceLadder;

use Kalaka\Ledger\Api\Payer;
use Kalaka\PriceLadder\Api\Tier;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;

/**
 * The price ladder's fee component, at the tier stage: the applying tier's amount, owed by the participant.
 * No applying tier owes nothing here, and core's fixed price, when the event has one, is the base instead.
 * It reads the ladder as it is now (no tariff snapshot), or the proposed tiers in a preview. The share of a
 * tier (who else pays part of it) is S29's: until then the participant owes the whole tier.
 */
final class PriceLadderComponent implements FeeComponent
{
    public const ID = 'price_ladder';

    /** @param list<Tier>|null $proposed the tiers to compose with instead of the stored ones (a preview) */
    public function __construct(
        private readonly TierRepository $tiers = new TierRepository(),
        private readonly TierSelection $selection = new TierSelection(),
        private readonly ?array $proposed = null,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function stage(): FeeStage
    {
        return FeeStage::Tier;
    }

    public function targets(FeeComponentInput $input): FeeTargets
    {
        $tiers = $this->proposed ?? array_map(
            static fn (TierRecord $record): Tier => $record->tier(),
            $this->tiers->forEvent($input->registration->eventId)
        );
        if ($tiers === []) {
            return FeeTargets::none();
        }
        $tier = $this->selection->select($tiers, $input->facts, $input->facts->registeredAt);
        if ($tier === null || !$tier->amount->isPositive()) {
            return FeeTargets::none();
        }

        return new FeeTargets([Payer::PARTICIPANT => $tier->amount]);
    }
}
