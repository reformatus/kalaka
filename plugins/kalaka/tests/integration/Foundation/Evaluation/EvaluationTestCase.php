<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Evaluation;

use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Api\UseCase\UseCase;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Foundation\Evaluation\Evaluation;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Foundation\AdminProbe;
use Kalaka\Tests\Integration\Foundation\RepositoryTestCase;
use Kalaka\Tests\Integration\Foundation\TestItem;

/**
 * The base of the evaluation tests: the site zone is Europe/Budapest, the clock stands at
 * 2026-11-30 10:00 there, and the runner evaluates over a registry the test fills.
 */
abstract class EvaluationTestCase extends RepositoryTestCase
{
    protected DecisionRules $rules;

    protected UserActor $admin;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        update_option('timezone_string', 'Europe/Budapest');
        $this->rules = new DecisionRules();
        $this->admin = new UserActor($this->fullAccessUser());
        $this->useClock('2026-11-30 10:00:00');
    }

    protected function useClock(string $local): void
    {
        $this->runner = new Runner($this->clockAt($local), true, new Evaluation($this->rules));
    }

    /** @param array<string, mixed> $input */
    protected function exec(UseCase $useCase, array $input = [], ?Actor $actor = null): mixed
    {
        return $this->runner->run($useCase, $input, $actor ?? $this->admin);
    }

    /** A use case that locks the registrations, then writes one test record mapped to each of them. */
    protected function touching(RegistrationKey ...$keys): AdminProbe
    {
        return new AdminProbe(function (array $input, Context $context) use ($keys): void {
            $this->lockRegistrations($context, ...$keys);
            foreach ($keys as $key) {
                $this->items->upsert(new TestItem('touch', regEvent: $key->eventId, regCode: $key->code), $context);
            }
        });
    }

    /** @return list<array<string, ?string>> */
    protected function decisionRows(): array
    {
        return $this->allRows('kalaka_decisions', 'id');
    }

    /** @return list<array<string, ?string>> */
    protected function flagRows(): array
    {
        return $this->allRows('kalaka_evaluation_failures', 'event_id, code');
    }

    /** Writes a registration row directly, behind the repository: a key without one gets no decision. */
    protected function seedRegistration(int $event, string ...$codes): void
    {
        global $wpdb;
        foreach ($codes as $code) {
            $wpdb->insert($wpdb->prefix . 'kalaka_registrations', [
                'event_id' => $event,
                'code' => $code,
                'version' => 1,
                'status' => 'registered',
                'secret' => 'secret',
                'created_at' => '2026-11-29 08:00:00.000000',
            ]);
        }
    }

    /** Writes a flag directly, behind the repository. */
    protected function seedFlag(int $event, string $code): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_evaluation_failures', [
            'event_id' => $event,
            'code' => $code,
            'version' => 1,
            'rule' => 'decisions/old',
            'error' => '{}',
            'failed_at' => '2026-11-29 08:00:00.000000',
        ]);
    }

    /** Writes a decision directly, behind the repository. */
    protected function seedDecision(int $event, string $code, string $kind, string $state): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_decisions', [
            'event_id' => $event,
            'code' => $code,
            'kind' => $kind,
            'state' => $state,
            'effective_at' => '2026-11-29 08:00:00.000000',
            'recorded_at' => '2026-11-29 08:00:00.000000',
            'cause_type' => 'test_record',
            'cause_event_id' => 12,
            'cause_key' => '1',
            'actor_type' => 'system',
        ]);
    }

    /** @return list<array<string, ?string>> */
    private function allRows(string $table, string $order): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test setup or inspection; the statement is built from the table prefix and constants only
        $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}{$table} ORDER BY {$order}", ARRAY_A);

        return array_values(is_array($rows) ? $rows : []);
    }

    /** Makes every INSERT into the flag table invalid SQL; nothing else is touched. */
    protected function breakFlagWrites(): void
    {
        add_filter('query', static function (string $query): string {
            return preg_match('/^\s*(INSERT|UPDATE|DELETE)\b.*kalaka_evaluation_failures\b/is', $query) === 1
                ? 'INSERT INTO kalaka_flag_broken (' : $query;
        });
    }
}
