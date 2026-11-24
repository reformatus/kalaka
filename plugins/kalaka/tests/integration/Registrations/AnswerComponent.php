<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Registrations\Api\Registrations;

/**
 * A test fee component standing for B0's support and aid: it reads the source's own field value, the fact the
 * answer act writes (`support_answer` holding `approved`, or `aid_award` holding the amount), never the answer
 * rows, and reduces the participant's part by `$fixed` (the offer's amount) or by the award (aid).
 */
final class AnswerComponent implements FeeComponent
{
    public function __construct(
        private readonly string $id,
        private readonly FeeStage $stage,
        private readonly string $field,
        private readonly Registrations $registrations,
        private readonly ?string $fixed = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function stage(): FeeStage
    {
        return $this->stage;
    }

    public function targets(FeeComponentInput $input): FeeTargets
    {
        $value = $this->registrations->values($input->registration)[$this->field] ?? null;
        if ($value === null) {
            return FeeTargets::none();
        }
        if ($this->fixed !== null) {
            $amount = $value === 'approved' ? Money::of($this->fixed, $input->currency) : null;
        } else {
            $amount = Money::of($value, $input->currency);
        }
        if ($amount !== null && $amount->isPositive()) {
            return new FeeTargets([Payer::PARTICIPANT => $amount->negated()]);
        }

        return FeeTargets::none();
    }
}
