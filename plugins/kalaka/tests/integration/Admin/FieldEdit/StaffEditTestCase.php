<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin\FieldEdit;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\FieldEdit\EditFieldValue;
use Kalaka\Admin\FieldEdit\PreparedEdit;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Foundation\Evaluation\Evaluation;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\Registrations\Api\FieldCorrections;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\SeatPool;
use Kalaka\Registrations\Api\Seats;
use Kalaka\Tests\Integration\Admin\AdminTestCase;
use Kalaka\Tests\Integration\Foundation\Evaluation\TestRule;
use Kalaka\Tests\Integration\Foundation\TestItemRepository;
use Kalaka\Tests\Integration\Registrations\TestQuotaPool;

/**
 * The base of the staff edit tests: an event with registrations `00A0` and `00A2`, a `tier` field whose two values
 * `a` and `b` each claim a seat in a quota pool (a test claim source, as a price tier's will be), and a runner
 * that evaluates over a rule the test can count.
 */
abstract class StaffEditTestCase extends AdminTestCase
{
    protected int $event;

    protected Seats $seats;

    protected TestRule $rule;

    protected TestQuotaPool $poolA;

    protected TestQuotaPool $poolB;

    /** @var int how often the evaluation asked the test rule */
    protected int $evaluated = 0;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        Access::forget();
        TestItemRepository::create('kalaka_test_items');
        global $wpdb;
        $rows = [];
        foreach (['A', 'B'] as $label) {
            $wpdb->insert($wpdb->prefix . 'kalaka_test_items', ['label' => $label, 'version' => 1]);
            $rows[$label] = (int) $wpdb->insert_id;
        }
        $this->event = $this->createEvent();
        $this->poolA = new TestQuotaPool($this->event, 'tier.a', 'kalaka_test_items', $rows['A'], 2);
        $this->poolB = new TestQuotaPool($this->event, 'tier.b', 'kalaka_test_items', $rows['B'], 1);
        $this->seats = new Seats();

        $rules = new DecisionRules();
        $this->rule = new TestRule('test_state');
        $this->rule->onDecide = function (): void {
            ++$this->evaluated;
        };
        $rules->add($this->rule, true);
        $this->runner = new Runner($this->clockAt('2026-11-30 10:00:00'), true, new Evaluation($rules));
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        Access::forget();
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test setup; the statement is built from the table prefix and a constant only
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}kalaka_test_items");

        parent::tear_down();
    }

    /** @return array<string, ?string> the newest audit entry */
    protected function lastEntry(): array
    {
        $entries = $this->entries();
        self::assertNotSame([], $entries);

        return $entries[count($entries) - 1];
    }

    protected function key(string $code): RegistrationKey
    {
        return new RegistrationKey($this->event, $code);
    }

    protected function field(string $key = TierClaimSource::FIELD, string $source = 'form'): ParticipantField
    {
        return new ParticipantField($key, 'kalaka/choice-field', $key, [], $source, false);
    }

    protected function corrections(): FieldCorrections
    {
        return new FieldCorrections([new TierClaimSource(['a' => $this->poolA, 'b' => $this->poolB])]);
    }

    /** Takes a seat for the registration by hand, as its earlier submit did. */
    protected function seat(string $code, SeatPool $pool): void
    {
        $this->inRun(function (array $input, \Kalaka\Foundation\Api\UseCase\Context $context) use ($code, $pool): void {
            $key = $this->key($code);
            $this->seats->lock($pool, $context);
            (new \Kalaka\Registrations\Api\Registrations())->lock($key, $context);
            $this->seats->retake($pool, $key, \Kalaka\Registrations\Api\SeatAct::Submit, $context);
        });
    }

    protected function inRun(\Closure $work): mixed
    {
        return $this->runner->run(new \Kalaka\Tests\Integration\Foundation\AdminProbe($work), [], $this->admin);
    }

    /**
     * Runs the edit as the actor (full access by default).
     *
     * @param array<string, mixed> $more input keys beyond the basics, e.g. `expected`
     * @return array{changed: bool, over_limit: list<string>}
     */
    protected function edit(
        string $code,
        ?string $stored,
        string $field = TierClaimSource::FIELD,
        array $more = [],
        ?Actor $actor = null,
    ): array {
        $useCase = new EditFieldValue(
            new PreparedEdit($this->event, $this->field($field), $stored),
            $this->corrections(),
        );
        $result = $this->exec(
            $useCase,
            ['event_id' => $this->event, 'code' => $code, 'field' => $field, 'value' => $stored, 'reason' => 'Elírás']
                + $more,
            $actor,
        );
        self::assertIsArray($result);

        /** @var array{changed: bool, over_limit: list<string>} $result */
        return $result;
    }

    protected function valueOf(string $code, string $field): ?string
    {
        return (new \Kalaka\Registrations\Api\Registrations())->values($this->key($code))[$field] ?? null;
    }

    /**
     * @param list<array<string, mixed>> $columns
     * @param list<array<string, mixed>> $filter
     */
    protected function viewWithColumns(array $columns, array $filter): int
    {
        $id = $this->exec(new \Kalaka\Admin\View\SaveView(), [
            'event_id' => $this->event,
            'name' => 'Szerkesztők',
            'filter' => $filter,
            'columns' => $columns,
        ]);
        self::assertIsInt($id);

        return $id;
    }

    protected function plainUser(): int
    {
        return self::factory()->user->create(['role' => 'editor']);
    }

    /** @param array<string, mixed> $overrides */
    protected function saveGrant(int $event, int $user, array $overrides): int
    {
        $id = $this->exec(new \Kalaka\Admin\Grant\SaveGrant(), $overrides + [
            'event_id' => $event,
            'user_id' => $user,
            'view_id' => null,
            'bound_value' => null,
            'actions' => [],
        ]);
        self::assertIsInt($id);

        return $id;
    }
}
