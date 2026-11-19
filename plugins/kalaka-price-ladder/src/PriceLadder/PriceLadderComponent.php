<?php

declare(strict_types=1);

namespace Kalaka\PriceLadder;

use Kalaka\Forms\Api\Value;
use Kalaka\Ledger\Api\Payer;
use Kalaka\PriceLadder\Api\Tier;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Pricing\Api\ProvidesBase;

/**
 * The price ladder's fee component, at the tier stage: the chosen tier's current amount, owed by the
 * participant (`TierSelection`: the tier chosen in `price_tier`; no answer, or a tier no longer in the ladder,
 * refuses). An event with tiers has its base from the ladder (`providesBase()`), so core's fixed price is not
 * charged. It puts the applied tier into the facts of the later stages as the
 * computed value `price_tier.applied`, never stored. It reads the ladder as it is now (no tariff snapshot), or
 * the proposed tiers in a preview. The ladder sets only the base price; who pays how much of it is decided by
 * the registrant's answers (the share extension, S29), never by the tier.
 */
final class PriceLadderComponent implements ProvidesBase
{
    public const ID = 'price_ladder';

    /** The participant field the ladder declares, a choice of its tiers (the owner's ruling 2026-11-19). */
    public const FIELD = 'price_tier';

    /** The field's title, the declarer's text. [UNAPPROVED, #200 T1] */
    public const FIELD_TITLE = 'Díjkategória';

    /** The computed value of the applied tier, for the later stages. [UNAPPROVED, #200 N12] */
    public const APPLIED = 'price_tier.applied';

    /** The block type of a choice field, core's. */
    public const CHOICE_TYPE = 'kalaka/choice-field';

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
        $tier = $this->selection->select($tiers, self::chosen($input));
        $computed = [self::APPLIED => Value::choice([$tier->key], $tier->label)];

        return new FeeTargets(
            $tier->amount->isPositive() ? [Payer::PARTICIPANT => $tier->amount] : [],
            computed: $computed
        );
    }

    /** The tier the registration chose in `price_tier`, or null: no answer, or no such field yet. */
    private static function chosen(FeeComponentInput $input): ?string
    {
        if (!$input->facts->has(self::FIELD)) {
            return null;
        }
        $data = $input->facts->get(self::FIELD)->data;

        return is_array($data) && $data !== [] ? $data[0] : null;
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
