<?php

declare(strict_types=1);

namespace Kalaka\Extras\Donation;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;

/**
 * The donation's fee component, at the extras stage: the whole-number value of the event's number field
 * (its donation setting), owed by the participant as one `donation` line. Empty, 0, a negative or a
 * fractional value owes nothing and writes no line. The congregation share never covers it: the share
 * reads only the earlier stages. It reads the setting as it is now, or the proposed one in a preview.
 * [UNAPPROVED N5, N14]
 */
final class DonationComponent implements FeeComponent
{
    public const ID = 'donation';

    /**
     * @param bool $proposing whether `$proposedField` stands in for the stored setting (a preview)
     * @param string|null $proposedField the proposed number field; null: no donation
     */
    public function __construct(
        private readonly DonationRepository $donations = new DonationRepository(),
        private readonly bool $proposing = false,
        private readonly ?string $proposedField = null,
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
        $fieldKey = $this->proposing
            ? $this->proposedField
            : $this->donations->find($input->registration->eventId)?->fieldKey;
        if ($fieldKey === null) {
            return FeeTargets::none();
        }
        try {
            $value = $input->facts->get($fieldKey);
        } catch (SystemFailure) {
            return FeeTargets::none();
        }
        if (!is_string($value->data) || preg_match('/\A[0-9]+\z/', $value->data) !== 1) {
            return FeeTargets::none();
        }
        $amount = Money::of($value->data, $input->currency);

        return $amount->isPositive() ? new FeeTargets([Payer::PARTICIPANT => $amount]) : FeeTargets::none();
    }
}
