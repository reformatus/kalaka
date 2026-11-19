<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Admin\Api\Access;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Foundation\Evaluation\Evaluation;
use Kalaka\Pricing\Api\ConfirmationRule;
use Kalaka\Pricing\Api\Materializer;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Pricing\SetFixedPrice;
use Kalaka\Pricing\SetManualConfirmation;
use Kalaka\Pricing\Unconfirm;
use Kalaka\Tests\Integration\Ledger\ManualEntryTestCase;

/** S20: setting the manual confirmation flag and unconfirming as WordPress abilities, through WordPress's own path. */
final class ManualConfirmationAbilityTest extends ManualEntryTestCase
{
    private Runner $production;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress names this hook
    public function set_up(): void
    {
        parent::set_up();

        $this->production = Runner::current();
        Runner::publish($this->runner);
        Access::forget();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress names this hook
    public function tear_down(): void
    {
        Runner::publish($this->production);
        wp_set_current_user(0);
        Access::forget();

        parent::tear_down();
    }

    protected function runnerAt(string $local): Runner
    {
        $money = new Materializer(new CoreFeeComponents([]));
        $rules = new DecisionRules();
        $rules->add(new ConfirmationRule($money->composition()), true, true);

        return new Runner($this->clockAt($local), true, new Evaluation($rules, money: $money));
    }

    private function ability(string $name): \WP_Ability
    {
        $ability = wp_get_ability($name);
        self::assertInstanceOf(\WP_Ability::class, $ability, $name);

        return $ability;
    }

    public function testBothAreAbilitiesOfTheKalakaCategoryWithTheirInputSchema(): void
    {
        foreach (
            [
                'kalaka/set-manual-confirmation' => [new SetManualConfirmation(), 'Visszaigazolás fizetés nélkül'],
                'kalaka/unconfirm' => [new Unconfirm(), 'Visszaigazolás visszavonása'],
            ] as $name => [$useCase, $label]
        ) {
            $ability = $this->ability($name);
            self::assertSame($label, $ability->get_label());
            self::assertSame('kalaka', $ability->get_category());
            self::assertSame($useCase->inputSchema(), $ability->get_input_schema());
            self::assertTrue($ability->get_meta()['show_in_rest']);
        }
    }

    public function testAFullAccessUserSetsAndUnconfirmsThroughTheAbilities(): void
    {
        wp_set_current_user($this->admin->userId);
        $this->exec(new SetFixedPrice(), ['event_id' => $this->event, 'amount' => '8000', 'currency' => 'HUF']);
        $input = ['event_id' => $this->event, 'code' => '00A0', 'reason' => 'Teszt indok'];

        self::assertSame(['changed' => true], $this->ability('kalaka/set-manual-confirmation')->execute($input));
        Runner::publish($this->runnerAt('2026-12-01 10:00'));
        self::assertSame(['changed' => true], $this->ability('kalaka/unconfirm')->execute($input));
    }

    public function testWordPressRefusesAMissingReason(): void
    {
        wp_set_current_user($this->admin->userId);

        $result = $this->ability('kalaka/set-manual-confirmation')->execute(
            ['event_id' => $this->event, 'code' => '00A0']
        );

        self::assertInstanceOf(\WP_Error::class, $result);
    }
}
