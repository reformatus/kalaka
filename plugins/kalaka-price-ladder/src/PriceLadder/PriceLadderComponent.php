<?php

declare(strict_types=1);

namespace Kalaka\PriceLadder;

use Kalaka\Ledger\Api\Payer;
use Kalaka\PriceLadder\Api\Tier;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Pricing\Api\ProvidesBase;

/**
 * The price ladder's fee component, at the tier stage: the applying tier's amount, owed by the participant.
 * An event with tiers has its base from the ladder (`providesBase()`), so core's fixed price is not charged,
 * even to a registration no tier applies to, or whose tier owes nothing. It reads the ladder as it is now (no
 * tariff snapshot), or the proposed tiers in a preview. The ladder sets only the base price; who pays how much
 * of it is decided by the registrant's answers (the share extension, S29), never by the tier.
 */
final class PriceLadderComponent implements ProvidesBase
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

    public function providesBase(int $eventId): bool
    {
        return $this->tiersOf($eventId) !== [];
    }

    public function targets(FeeComponentInput $input): FeeTargets
    {
        $tiers = $this->tiersOf($input->registration->eventId);
        if ($tiers === []) {
            return FeeTargets::none();
        }
        $tier = $this->selection->select($tiers, $input->facts, $input->facts->registeredAt);
        if ($tier === null || !$tier->amount->isPositive()) {
            return FeeTargets::none();
        }

        return new FeeTargets([Payer::PARTICIPANT => $tier->amount]);
    }

    /** @return list<Tier> */
    private function tiersOf(int $eventId): array
    {
        return $this->proposed ?? array_map(
            static fn (TierRecord $record): Tier => $record->tier(),
            $this->tiers->forEvent($eventId)
        );
    }
}
