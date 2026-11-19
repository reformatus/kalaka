<?php

declare(strict_types=1);

namespace Kalaka\Extras\Lunch;

use Kalaka\Extras\Api\Lunch;
use Kalaka\Extras\Api\LunchItem;
use Kalaka\Forms\Api\Availability;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\ConfigurableFeeComponent;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Pricing\Api\PlainAmount;
use Kalaka\Pricing\Api\ProposedComponentSettings;

/**
 * Lunch's fee component, at the extras stage: the sum of the event's lunch items whose condition holds over
 * the registration's facts, owed by the participant, as one `lunch` line (the materializer keeps one line
 * source per component and payer). The congregation share never covers it: the share reads only the earlier
 * stages. It reads the items as they are now (no tariff snapshot), or the proposed ones in a preview.
 * [UNAPPROVED N4]
 */
final class LunchComponent implements ConfigurableFeeComponent
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

    public function settingsToArray(int $eventId): array
    {
        $items = [];
        foreach ((new Lunch())->items($eventId) as $item) {
            $row = ['key' => $item->key, 'label' => $item->label, 'amount' => PlainAmount::of($item->amount)];
            if ($item->condition !== null) {
                $row['condition'] = $item->condition;
            }
            $items[] = $row;
        }

        return ['items' => $items];
    }

    public function settingsFromArray(int $eventId, array $settings): ProposedComponentSettings
    {
        $items = $settings['items'] ?? null;
        if (array_keys($settings) !== ['items'] || !is_array($items) || !array_is_list($items)) {
            throw new InvalidInput();
        }

        return (new Lunch())->propose($eventId, $items);
    }
}
