<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ledger;

use Kalaka\Admin\Grant\SaveGrant;
use Kalaka\Admin\View\SaveView;
use Kalaka\Foundation\Api\Module\ActionCatalogue;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Foundation\Evaluation\Evaluation;
use Kalaka\Pricing\Api\Materializer;
use Kalaka\Pricing\CoreFeeComponents;
use Kalaka\Tests\Integration\Admin\AdminTestCase;

/** The base of the manual payment and refund tests: an event, registrations, views, grants and a fixed clock. */
abstract class ManualEntryTestCase extends AdminTestCase
{
    protected int $event;

    private ActionCatalogue $catalogue;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        update_option('timezone_string', 'Europe/Budapest');
        $this->catalogue = ActionCatalogue::current();
        ActionCatalogue::record(new ActionCatalogue([
            'kalaka_record_payment',
            'kalaka_record_refund',
            'kalaka_view_money',
        ]));
        $this->runner = $this->runnerAt('2026-11-30 10:00');
        $this->event = $this->createEvent();
        $this->addRegistration($this->event, '00A0');
    }

    /** A runner whose evaluation has the money phase, with the clock at the local time. */
    protected function runnerAt(string $local): Runner
    {
        return new Runner(
            $this->clockAt($local),
            true,
            new Evaluation(new DecisionRules(), money: new Materializer(new CoreFeeComponents([])))
        );
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        ActionCatalogue::record($this->catalogue);

        parent::tear_down();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function paymentInput(array $overrides = []): array
    {
        return $overrides + [
            'event_id' => $this->event,
            'code' => '00A0',
            'amount' => '5000',
            'currency' => 'HUF',
            'method' => 'bank_transfer',
            'value_date' => '2026-11-29',
            'idempotency_key' => 'k1',
        ];
    }

    /**
     * A grant of the actions on a view of the event holding the registrations of the status.
     *
     * @param list<string> $actions
     */
    protected function grantOn(int $user, array $actions, string $status = 'registered'): int
    {
        $view = $this->exec(new SaveView(), [
            'event_id' => $this->event,
            'name' => 'Nézet',
            'filter' => [['column' => 'status', 'in' => [$status]]],
            'columns' => [['column' => 'code', 'write' => false]],
        ]);
        self::assertIsInt($view);
        $grant = $this->exec(new SaveGrant(), [
            'event_id' => $this->event,
            'user_id' => $user,
            'view_id' => $view,
            'bound_value' => null,
            'actions' => $actions,
        ]);
        self::assertIsInt($grant);

        return $grant;
    }

    protected function plainUser(): int
    {
        return self::factory()->user->create(['role' => 'editor']);
    }

    /** @return list<array<string, ?string>> */
    protected function lines(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM %i WHERE event_id = %d ORDER BY id', $wpdb->prefix . 'kalaka_ledger_lines', $this->event),
            ARRAY_A
        );
        self::assertIsArray($rows);

        /** @var list<array<string, ?string>> $rows */
        return $rows;
    }

    /** @return list<array<string, ?string>> */
    protected function notes(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM %i WHERE event_id = %d ORDER BY line_id', $wpdb->prefix . 'kalaka_payment_notes', $this->event),
            ARRAY_A
        );
        self::assertIsArray($rows);

        /** @var list<array<string, ?string>> $rows */
        return $rows;
    }
}
