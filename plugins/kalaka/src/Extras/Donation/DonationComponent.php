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
 * The donation's fee component, at the extras stage: the value of the event's number field with the fixed key
 * `adomany` (only a number field; a consent or a text field with the key is not the donation), owed by the
 * participant as one `donation` line, exactly as stored (no whole-number rule; the form field's own settings
 * decide whether decimals are allowed). Empty, 0, a negative value or an event without that field owes nothing
 * and writes no line. There is no setting: the key alone decides. The congregation share never covers it: the
 * share reads only the earlier stages. [UNAPPROVED N5]
 */
final class DonationComponent implements FeeComponent
{
    public const ID = 'donation';

    /** The key of the participant field that is the event's donation, fixed by Kaláka. */
    public const KEY = 'adomany';

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
        $kind = $input->facts->fieldKind(self::KEY);
        if ($kind === null || !$kind->isNumber()) {
            return FeeTargets::none();
        }
        try {
            $value = $input->facts->get(self::KEY);
        } catch (SystemFailure) {
            return FeeTargets::none();
        }
        if (!is_string($value->data) || preg_match('/\A-?[0-9]+(\.[0-9]+)?\z/', $value->data) !== 1) {
            return FeeTargets::none();
        }
        $amount = Money::of($value->data, $input->currency);

        return $amount->isPositive() ? new FeeTargets([Payer::PARTICIPANT => $amount]) : FeeTargets::none();
    }
}
