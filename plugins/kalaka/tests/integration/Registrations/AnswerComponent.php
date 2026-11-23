<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Registrations\Api\Requests;

/**
 * A test fee component standing for B0's support and aid: it reduces the participant's part only while the
 * request's latest answer approves it, by `$fixed` (the offer's amount) or by the answer's amount (aid).
 */
final class AnswerComponent implements FeeComponent
{
    public function __construct(
        private readonly string $id,
        private readonly FeeStage $stage,
        private readonly string $request,
        private readonly Requests $requests,
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
        foreach ($this->requests->states($input->registration) as $state) {
            $answer = $state->answer;
            if ($state->request->id !== $this->request || $answer === null || !$answer->approved) {
                continue;
            }
            $amount = $this->fixed !== null ? Money::of($this->fixed, $input->currency) : $answer->amount;
            if ($amount !== null && $amount->isPositive()) {
                return new FeeTargets([Payer::PARTICIPANT => $amount->negated()]);
            }
        }

        return FeeTargets::none();
    }
}
