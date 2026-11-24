<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Pricing\Api\ManualConfirmations;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Sets the manual confirmation flag ("may come without paying", money.md, "Confirmation") with its reason and
 * the run's moment as its set time; the evaluation then confirms the registration in the same run. Only a
 * `registered` registration can be set (the owner's topic 1 ruling, H3): any other status is `InvalidInput`.
 * A set flag stays as it is: its set time and reason are kept. [UNAPPROVED N6]
 */
final class SetManualConfirmation extends ManualConfirmationChange
{
    public function __construct(
        ManualConfirmationRepository $flags = new ManualConfirmationRepository(),
        private readonly ManualConfirmations $confirmations = new ManualConfirmations(),
    ) {
        parent::__construct($flags);
    }

    public static function name(): string
    {
        return 'kalaka/set-manual-confirmation';
    }

    protected function change(
        RegistrationKey $key,
        ?ManualConfirmationRecord $flag,
        string $reason,
        Context $context,
    ): bool {
        if (!$this->confirmations->canSet($key)) {
            throw new InvalidInput();
        }
        if ($flag !== null && $flag->isSet()) {
            return false;
        }
        if ($flag === null) {
            $flag = new ManualConfirmationRecord($key, $context->now(), self::userOf($context->actor()), $reason);
        } else {
            $flag->setAt = $context->now();
            $flag->setBy = self::userOf($context->actor());
            $flag->reason = $reason;
            $flag->revokedAt = null;
        }
        $this->flags->upsert($flag, $context);

        return true;
    }
}
