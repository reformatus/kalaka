<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Pricing;

use Kalaka\Admin\Api\Access;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Pricing\RevokeManualConfirmation;
use Kalaka\Pricing\SetManualConfirmation;
use Kalaka\Tests\Integration\Ledger\ManualEntryTestCase;

/** S20: setting and revoking the manual confirmation flag as WordPress abilities, through WordPress's own path. */
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
                'kalaka/revoke-manual-confirmation' => [
                    new RevokeManualConfirmation(),
                    'Fizetés nélküli visszaigazolás visszavonása',
                ],
            ] as $name => [$useCase, $label]
        ) {
            $ability = $this->ability($name);
            self::assertSame($label, $ability->get_label());
            self::assertSame('kalaka', $ability->get_category());
            self::assertSame($useCase->inputSchema(), $ability->get_input_schema());
            self::assertTrue($ability->get_meta()['show_in_rest']);
        }
    }

    public function testAFullAccessUserSetsAndRevokesThroughTheAbilities(): void
    {
        wp_set_current_user($this->admin->userId);
        $input = ['event_id' => $this->event, 'code' => '00A0', 'reason' => 'Teszt indok'];

        self::assertSame(['changed' => true], $this->ability('kalaka/set-manual-confirmation')->execute($input));
        self::assertSame(['changed' => true], $this->ability('kalaka/revoke-manual-confirmation')->execute($input));
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
