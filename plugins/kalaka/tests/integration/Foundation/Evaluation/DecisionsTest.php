<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Evaluation;

use Kalaka\Foundation\Api\Decision\DecisionDefault;
use Kalaka\Foundation\Api\Decision\DecisionKind;
use Kalaka\Foundation\Api\Decision\Decisions;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use WP_UnitTestCase;

/** The public reader of the declared decision kinds and of the states recorded in an event. */
final class DecisionsTest extends WP_UnitTestCase
{
    public function testKindsAreTheDeclaredKindsInReadingOrderWithTheirLabelsAndStates(): void
    {
        $rules = new DecisionRules();
        $rules->add(new TestRule('later', ['x' => 'Iksz'], label: 'Későbbi'), false);
        $first = new TestRule('first', ['b' => 'Bé', 'a' => 'Á'], label: 'Első');
        $first->marks = ['a' => ['icon' => 'yes-alt', 'tone' => 'ok']];
        $rules->add($first, true);
        $decisions = new Decisions(rules: $rules);

        self::assertEquals(
            [
                new DecisionKind(
                    'first',
                    'Első',
                    ['b' => 'Bé', 'a' => 'Á'],
                    marks: ['a' => ['icon' => 'yes-alt', 'tone' => 'ok']]
                ),
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
        self::assertEquals(
            new DecisionDefault('unconfirmed', [RegistrationStatus::Registered]),
            $kinds[0]->default,
            'The owner\'s ruling 2026-11-19: no record is the base state, unconfirmed, for a registered one only.'
        );
    }

    public function testABaseStateMustBeADeclaredState(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DecisionRules())->add(
            new TestRule('based', default: new DecisionDefault('missing', [RegistrationStatus::Registered])),
            true
        );
    }

    /**
     * The owner's ruling 2026-11-19: a kind's base state is read where it has no record, only in the statuses
     * it holds for; in another status the kind reads no state, records or not. The kinds and states in an
     * event include it.
     */
    public function testTheReadsApplyABaseStateInItsStatusesOnly(): void
    {
        $rules = new DecisionRules();
        $rules->add(
            new TestRule('based', default: new DecisionDefault('off', [RegistrationStatus::Registered])),
            true
        );
        $rules->add(new TestRule('plain'), false);
        $decisions = new Decisions(rules: $rules);
        foreach (['00A0' => 'registered', '00A2' => 'registered', '00A5' => 'waitlisted'] as $code => $status) {
            $this->registration(1, $code, $status);
        }
        $this->record(1, '00A2', 'based', 'on');
        $this->record(1, '00A5', 'based', 'on');
        $this->record(1, '00A5', 'plain', 'on');

        $states = $decisions->currentStates(1, [
            '00A0' => RegistrationStatus::Registered,
            '00A2' => RegistrationStatus::Registered,
            '00A5' => RegistrationStatus::Waitlisted,
        ], ['based', 'plain']);
        ksort($states);
        self::assertSame(
            ['00A0' => ['based' => 'off'], '00A2' => ['based' => 'on'], '00A5' => ['plain' => 'on']],
            $states
        );
        $first = $decisions->currentOf(new RegistrationKey(1, '00A0'));
        self::assertSame(['based'], array_keys($first));
        self::assertSame('off', $first['based']->state);
        self::assertSame('2026-11-29 08:00:00', $first['based']->effectiveAt->format('Y-m-d H:i:s'));
        self::assertNull($first['based']->cause);
        self::assertSame(['plain'], array_keys($decisions->currentOf(new RegistrationKey(1, '00A5'))));
        self::assertSame(['based'], $decisions->kindsIn(2), 'A kind with a base state holds with no record.');
        self::assertSame(['off', 'on'], $decisions->statesIn(1, 'based'));
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

    private function registration(int $event, string $code, string $status): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_registrations', [
            'event_id' => $event,
            'code' => $code,
            'version' => 1,
            'status' => $status,
            'secret' => str_pad($code, 22, 'x'),
            'created_at' => '2026-11-29 08:00:00.000000',
            'registered_at' => $status === 'registered' ? '2026-11-29 08:00:00.000000' : null,
        ]);
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
