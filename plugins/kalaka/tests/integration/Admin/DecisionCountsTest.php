<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\Grid\GridModel;
use Kalaka\Admin\View\SaveMasterCounts;
use Kalaka\Admin\View\SaveView;
use Kalaka\Foundation\Api\Decision\Decisions;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Tests\Integration\Foundation\Evaluation\TestRule;

/**
 * S37: a decision column has no special case. Its name and its states' names come from the kind's
 * declaration, else from the kind's key and the states recorded in the event; its sum cell counts the
 * members picked per view, stored in the view's counts and validated against those states. Nothing is
 * picked by default.
 */
final class DecisionCountsTest extends GrantTestCase
{
    private int $event;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->event = $this->createEvent();
        foreach (['00A0', '00A2', '00A5'] as $code) {
            $this->addRegistration($this->event, $code);
        }
        $this->addDecision($this->event, '00A0', 'approval', 'pending');
        $this->addDecision($this->event, '00A0', 'approval', 'granted');
        $this->addDecision($this->event, '00A2', 'approval', 'pending');
        $this->addDecision($this->event, '00A5', 'approval', 'granted');
        $this->addDecision($this->event, '00A5', 'hold', 'open');
    }

    /** The invented kind `approval`, declared in a registry of its own. */
    private function declared(): Decisions
    {
        $rules = new DecisionRules();
        $rules->add(
            new TestRule('approval', ['pending' => 'Függőben', 'granted' => 'Megadva'], label: 'Jóváhagyás'),
            false
        );

        return new Decisions(rules: $rules);
    }

    /** @param list<string> $members */
    private function pick(string $column, array $members): void
    {
        $input = ['event_id' => $this->event, 'column' => $column, 'members' => $members];
        $this->exec(SaveMasterCounts::prepared($input), $input);
    }

    public function testADeclaredKindGivesTheColumnItsLabelAndItsStatesAsMembers(): void
    {
        $held = (new Access())->master($this->admin, $this->event);
        $columns = array_column((new GridModel(decisions: $this->declared()))->columns($held, []), null, 'column');

        $column = $columns['decision:approval'];
        self::assertSame('Jóváhagyás', $column['label']);
        self::assertSame('decision', $column['type']);
        self::assertSame(['pending' => 'Függőben', 'granted' => 'Megadva'], $column['states']);
        self::assertSame(
            [['value' => 'pending', 'label' => 'Függőben'], ['value' => 'granted', 'label' => 'Megadva']],
            $column['members']
        );
    }

    public function testAnUndeclaredKindFallsBackToItsKeyAndTheRecordedStates(): void
    {
        $held = (new Access())->master($this->admin, $this->event);
        $columns = array_column((new GridModel(decisions: $this->declared()))->columns($held, []), null, 'column');

        $column = $columns['decision:hold'];
        self::assertSame('hold', $column['label']);
        self::assertSame(['open' => 'open'], $column['states']);
        self::assertSame([['value' => 'open', 'label' => 'open']], $column['members']);

        // Without any declaration the kind with several current states lists each, by key, in byte order.
        $plain = array_column((new GridModel())->columns($held, []), null, 'column');
        self::assertSame('approval', $plain['decision:approval']['label']);
        self::assertSame(['granted' => 'granted', 'pending' => 'pending'], $plain['decision:approval']['states']);
    }

    public function testNothingIsCountedUntilMembersArePickedAndThenOnlyThoseMembers(): void
    {
        $model = new GridModel(decisions: $this->declared());
        $held = (new Access())->master($this->admin, $this->event);
        self::assertArrayNotHasKey('decision:approval', $model->sums($held, []));

        $this->pick('decision:approval', ['granted']);
        $held = (new Access())->master($this->admin, $this->event);
        $sum = $model->sums($held, [])['decision:approval'];
        self::assertSame(2, $sum['n']);
        self::assertSame(['granted'], $sum['members']);
        self::assertSame(['Megadva'], $sum['labels']);
    }

    public function testCountedStatesAreValidatedAgainstTheKindsStates(): void
    {
        $this->pick('decision:approval', ['pending', 'granted']);
        self::assertSame(
            ['decision:approval' => ['pending', 'granted']],
            (new Access())->master($this->admin, $this->event)->counts()
        );

        foreach ([['revoked'], ['']] as $members) {
            try {
                $this->pick('decision:approval', $members);
                self::fail('Expected InvalidInput.');
            } catch (InvalidInput) {
                self::assertSame(
                    ['decision:approval' => ['pending', 'granted']],
                    (new Access())->master($this->admin, $this->event)->counts()
                );
            }
        }
    }

    public function testAViewStoresTheCountedStatesOfItsDecisionColumn(): void
    {
        $input = [
            'event_id' => $this->event,
            'name' => 'Jóváhagyások',
            'filter' => [],
            'columns' => [['column' => 'code', 'write' => false], ['column' => 'decision:approval', 'write' => false]],
            'counts' => ['decision:approval' => ['granted']],
        ];
        $id = $this->exec(SaveView::prepared($input), $input);
        self::assertIsInt($id);
        self::assertSame('{"decision:approval":["granted"]}', $this->viewRow($id)['counts']);

        $bad = ['counts' => ['decision:approval' => ['revoked']]] + $input;
        $this->expectException(InvalidInput::class);
        $this->exec(SaveView::prepared($bad), $bad);
    }
}
