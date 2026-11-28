<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Extras;

use Kalaka\Admin\FieldEdit\EditFieldValue;
use Kalaka\Admin\FieldEdit\FieldEditInput;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Foundation\Evaluation\Evaluation;
use Kalaka\Pricing\Api\ConfirmationRule;
use Kalaka\Pricing\Api\Confirmations;
use Kalaka\Pricing\Api\FeeComponent;
use Kalaka\Pricing\Api\Materializer;
use Kalaka\Pricing\Api\MoneySettings;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Foundation\TestClock;

/** B5-2: a staff edit of a charge answer is followed by money and confirmation in the same run. */
final class StaffExtraEditTest extends ExtrasTestCase
{
    /** The runner with core's confirmation decision too. */
    protected function useComponents(FeeComponent ...$more): void
    {
        $components = new CoreFeeComponents($more);
        $money = new Materializer($components);
        $rules = new DecisionRules();
        $rules->add(new ConfirmationRule($money->composition()), true);
        $this->runner = new Runner(
            new TestClock($this->utc($this->now)),
            true,
            new Evaluation($rules, money: $money)
        );
        $this->settings = new MoneySettings($this->runner, $components);
    }

    private function edit(RegistrationKey $key, string $field, ?string $value): void
    {
        $actor = new UserActor($this->fullAccessUser());
        $input = [
            'event_id' => $key->eventId,
            'code' => $key->code,
            'field' => $field,
            'value' => $value,
            'reason' => 'Javítás',
        ];
        $edit = (new FieldEditInput())->prepare($input, $actor);
        $this->runner->run(new EditFieldValue($edit), $input, $actor);
    }

    public function testTickingAChargeAnswerAddsItsLineAndAPaidRegistrationOwesAgainButStaysConfirmed(): void
    {
        $this->setPrice('40000');
        $this->applyCharges([self::item('ebed', 'Ebéd', '3500')]);
        $anna = $this->registerWith('00A0', ['ebed' => '0']);
        $this->pay($anna, '40000');
        $confirmations = new Confirmations();
        self::assertTrue($confirmations->isConfirmed($anna));
        self::assertSame('0', $this->balance($anna));

        $this->edit($anna, 'ebed', '1');

        self::assertSame('3500', $this->balance($anna));
        self::assertTrue($confirmations->isConfirmed($anna), 'A confirmed registration stays confirmed.');
        self::assertContains('extras:ebed participant 3500 charge:extras:ebed:participant:1', $this->charges($anna));
    }

    public function testUntickingTheChargeAnswerTakesItsLineAway(): void
    {
        $this->applyCharges([self::item('ebed', 'Ebéd', '3500')]);
        $anna = $this->registerWith('00A0', ['ebed' => '1']);
        self::assertSame('3500', $this->balance($anna));

        $this->edit($anna, 'ebed', '0');

        self::assertSame('0', $this->balance($anna));
    }
}
