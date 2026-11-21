<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Ledger\Api\Ledger;
use Kalaka\Ledger\Api\ManualFeeType;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Pricing\Api\LineName;
use Kalaka\Pricing\Api\NamedFeeComponent;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Core's fee component of the manual charges and discounts (money.md, "Recording payments by hand"): it
 * composes the Ledger's manual fee facts, one item (so one `charge` line) per entry, plus the amount for a
 * charge and minus it for a discount. It is the last stage, so it never touches support, share or aid caps.
 * It reads facts, never lines. The fee's type gives its name, „Kézi díj” or „Kézi kedvezmény” [UNAPPROVED T24],
 * never the line's sign (a withdrawal's reversal flips it).
 */
final class ManualFees implements NamedFeeComponent
{
    public const ID = 'manual';

    public function __construct(private readonly Ledger $ledger = new Ledger())
    {
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
        $items = [];
        foreach ($this->ledger->manualFeesOf($input->registration) as $fee) {
            $items[(string) $fee->id] = [$fee->payer => $fee->signedAmount()];
        }

        return FeeTargets::ofItems($items);
    }

    public function lineNames(RegistrationKey $registration, array $items): array
    {
        $wanted = array_map(static fn (?string $item): string => (string) $item, $items);
        $names = [];
        foreach ($this->ledger->manualFeesOf($registration) as $fee) {
            if (!in_array((string) $fee->id, $wanted, true)) {
                continue;
            }
            $names[$fee->id] = $fee->type === ManualFeeType::Charge
                ? new LineName(LineName::MANUAL_CHARGE, 'Kézi díj', $fee->title, $fee->recordedBy)
                : new LineName(LineName::MANUAL_DISCOUNT, 'Kézi kedvezmény', $fee->title, $fee->recordedBy);
        }

        return $names;
    }
}
