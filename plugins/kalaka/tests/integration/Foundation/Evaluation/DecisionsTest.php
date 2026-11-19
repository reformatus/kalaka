<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Evaluation;

use Kalaka\Foundation\Api\Decision\DecisionKind;
use Kalaka\Foundation\Api\Decision\Decisions;
use Kalaka\Foundation\Decision\DecisionRules;
use WP_UnitTestCase;

/** The public reader of the declared decision kinds and of the states recorded in an event. */
final class DecisionsTest extends WP_UnitTestCase
{
    public function testKindsAreTheDeclaredKindsInReadingOrderWithTheirLabelsAndStates(): void
    {
        $rules = new DecisionRules();
        $rules->add(new TestRule('later', ['x' => 'Iksz'], label: 'Későbbi'), false);
        $rules->add(new TestRule('first', ['b' => 'Bé', 'a' => 'Á'], label: 'Első'), true);
        $decisions = new Decisions(rules: $rules);

        self::assertEquals(
            [
                new DecisionKind('first', 'Első', ['b' => 'Bé', 'a' => 'Á']),
                new DecisionKind('later', 'Későbbi', ['x' => 'Iksz']),
            ],
            $decisions->kinds()
        );
        self::assertSame(['b', 'a'], array_keys($decisions->kinds()[0]->states));
    }

    public function testKindFindsADeclaredKindAndAnswersNullForAnyOther(): void
    {
        $rules = new DecisionRules();
        $rules->add(new TestRule('known', label: 'Ismert'), true);
        $decisions = new Decisions(rules: $rules);

        self::assertSame('Ismert', $decisions->kind('known')?->label);
        self::assertNull($decisions->kind('unknown'));
    }

    public function testOnARealSiteCoreDeclaresConfirmationWithItsApprovedLabels(): void
    {
        $kinds = (new Decisions())->kinds();

        self::assertCount(1, $kinds);
        self::assertSame('confirmation', $kinds[0]->kind);
        self::assertSame('Visszaigazolás', $kinds[0]->label);
        self::assertSame(
            ['confirmed' => 'Visszaigazolva', 'unconfirmed' => 'Nincs visszaigazolva'],
            $kinds[0]->states
        );
    }

    public function testStatesInAreTheDistinctRecordedStatesOfOneKindInOneEventInByteOrder(): void
    {
        $this->record(1, '00A0', 'kind_a', 'zed');
        $this->record(1, '00A0', 'kind_a', 'alfa');
        $this->record(1, '00A1', 'kind_a', 'alfa');
        $this->record(1, '00A1', 'kind_b', 'other');
        $this->record(2, '00A0', 'kind_a', 'elsewhere');
        $decisions = new Decisions();

        self::assertSame(['alfa', 'zed'], $decisions->statesIn(1, 'kind_a'));
        self::assertSame(['other'], $decisions->statesIn(1, 'kind_b'));
        self::assertSame([], $decisions->statesIn(1, 'kind_c'));
        self::assertSame([], $decisions->statesIn(3, 'kind_a'));
    }

    private function record(int $event, string $code, string $kind, string $state): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}kalaka_decisions (event_id, code, kind, state, effective_at, recorded_at,"
            . " cause_type, cause_event_id, cause_key, actor_type) VALUES (%d, %s, %s, %s,"
            . " '2026-11-29 08:00:00.000000', '2026-11-29 08:00:00.000000', 'test_record', 12, '1', 'system')",
            $event,
            $code,
            $kind,
            $state
        ));
    }
}
