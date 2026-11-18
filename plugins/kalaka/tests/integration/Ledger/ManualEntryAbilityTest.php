<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ledger;

use Kalaka\Admin\Api\Access;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Ledger\RecordPayment;
use Kalaka\Ledger\RecordRefund;

/** S22: the two use cases as WordPress abilities, through WordPress's own path. */
final class ManualEntryAbilityTest extends ManualEntryTestCase
{
    private Runner $production;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        // The abilities run the production runner; it joins the harness's open transaction here.
        $this->production = Runner::current();
        Runner::publish($this->runner);
        Access::forget();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
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
                'kalaka/record-payment' => [new RecordPayment(), 'Befizetés rögzítése'],
                'kalaka/record-refund' => [new RecordRefund(), 'Visszatérítés rögzítése'],
            ] as $name => [$useCase, $label]
        ) {
            $ability = $this->ability($name);
            self::assertSame($label, $ability->get_label());
            self::assertSame('kalaka', $ability->get_category());
            self::assertSame($useCase->inputSchema(), $ability->get_input_schema());
            self::assertTrue($ability->get_meta()['show_in_rest']);
        }
    }

    public function testAFullAccessUserRecordsThroughTheAbility(): void
    {
        wp_set_current_user($this->admin->userId);

        $result = $this->ability('kalaka/record-payment')->execute($this->paymentInput());

        self::assertIsArray($result);
        self::assertCount(1, $this->lines());
    }

    public function testAReusedKeyWithOtherInputIsA409NotAServerError(): void
    {
        wp_set_current_user($this->admin->userId);
        $this->ability('kalaka/record-payment')->execute($this->paymentInput());

        $result = $this->ability('kalaka/record-payment')->execute($this->paymentInput(['amount' => '9999']));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('kalaka_idempotency_conflict', $result->get_error_code());
        self::assertSame(409, $result->get_error_data()['status'] ?? null);
        self::assertSame('A művelet nem hajtható végre.', $result->get_error_message());
        self::assertCount(1, $this->lines());
    }

    public function testWordPressChecksTheInputAgainstTheSchema(): void
    {
        wp_set_current_user($this->admin->userId);

        $result = $this->ability('kalaka/record-payment')->execute($this->paymentInput(['amount' => 'x']));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame([], $this->lines());
    }

    public function testAGrantHolderPassesTheGateAndTheRunnersCheckDecides(): void
    {
        $user = $this->plainUser();
        $this->grantOn($user, ['kalaka_record_payment']);
        wp_set_current_user($user);
        Access::forget();

        self::assertTrue($this->ability('kalaka/record-payment')->check_permissions($this->paymentInput()));
        self::assertIsArray($this->ability('kalaka/record-payment')->execute($this->paymentInput()));
        self::assertCount(1, $this->lines());

        // The payment action does not carry the refund: the runner refuses it.
        $refused = $this->ability('kalaka/record-refund')->execute($this->paymentInput(['idempotency_key' => 'r1']));
        self::assertInstanceOf(\WP_Error::class, $refused);
        self::assertSame(403, $refused->get_error_data()['status'] ?? null);
        self::assertCount(1, $this->lines());
    }

    public function testAUserWithoutAGrantNeverReachesTheRunner(): void
    {
        wp_set_current_user($this->plainUser());
        Access::forget();

        $ability = $this->ability('kalaka/record-payment');

        self::assertFalse($ability->check_permissions($this->paymentInput()));
        self::assertInstanceOf(\WP_Error::class, $ability->execute($this->paymentInput()));
        self::assertSame([], $this->lines());
    }
}
