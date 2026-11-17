<?php

declare(strict_types=1);

namespace Kalaka\Extras\Lunch;

use Kalaka\Extras\Api\LunchItem;
use Kalaka\Forms\Api\Availability;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;

/**
 * Lunch's fee component, at the extras stage: the sum of the event's lunch items whose condition holds over
 * the registration's facts, owed by the participant, as one `lunch` line (the materializer keeps one line
 * source per component and payer). The congregation share never covers it: the share reads only the earlier
 * stages. It reads the items as they are now (no tariff snapshot), or the proposed ones in a preview.
 * [UNAPPROVED N4]
 */
final class LunchComponent implements FeeComponent
{
    public const ID = 'lunch';

    /** @param list<LunchItem>|null $proposed the items to compose with instead of the stored ones (a preview) */
    public function __construct(
        private readonly LunchItemRepository $items = new LunchItemRepository(),
        private readonly Availability $availability = new Availability(),
        private readonly ?array $proposed = null,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function stage(): FeeStage
    {
        return FeeStage::Extras;
    }

    public function targets(FeeComponentInput $input): FeeTargets
    {
        $items = $this->proposed ?? array_map(
            static fn (LunchItemRecord $record): LunchItem => $record->item(),
            $this->items->forEvent($input->registration->eventId)
        );
        if ($items === []) {
            return FeeTargets::none();
        }
        $total = Money::zero($input->currency);
        foreach ($this->availability->available($items, $input->facts) as $item) {
            $total = $total->plus($item->amount);
        }

        return $total->isPositive() ? new FeeTargets([Payer::PARTICIPANT => $total]) : FeeTargets::none();
    }
}
