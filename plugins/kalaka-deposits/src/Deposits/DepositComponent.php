<?php

declare(strict_types=1);

namespace Kalaka\Deposits;

use Kalaka\Deposits\Api\Deposits;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Pricing\Api\ConfigurableFeeComponent;
use Kalaka\Pricing\Api\FeeComponentInput;
use Kalaka\Pricing\Api\FeeStage;
use Kalaka\Pricing\Api\FeeTargets;
use Kalaka\Pricing\Api\ProposedComponentSettings;

/**
 * The deposit's fee component: the money settings file of `wp kalaka money-settings` carries the event's
 * deposit under the component ID `deposit`, and an apply saves it through `kalaka/set-deposit` and re-evaluates
 * every open registration (money.md, "Money settings apply"). It adds no fee line: the threshold, the advance
 * and the retention rule that read the deposit are the money side's, a later slice. It composes last (the
 * extras stage) and returns nothing.
 */
final class DepositComponent implements ConfigurableFeeComponent
{
    public const ID = 'deposit';

    public function __construct(
        private readonly DepositSettingsRepository $settings = new DepositSettingsRepository(),
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

    /** No fee line: this slice stores the deposit only. */
    public function targets(FeeComponentInput $input): FeeTargets
    {
        return FeeTargets::none();
    }

    public function settingsToArray(int $eventId): array
    {
        return ['amount' => (new Deposits($this->settings))->amount($eventId)];
    }

    public function settingsFromArray(int $eventId, array $settings): ProposedComponentSettings
    {
        if (array_keys($settings) !== ['amount']) {
            throw new InvalidInput();
        }
        $amount = $settings['amount'];
        if ($amount !== null && !is_string($amount)) {
            throw new InvalidInput();
        }

        return (new Deposits($this->settings))->propose($eventId, $amount);
    }
}
