<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin\FieldEdit;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\FieldEdit\EditFieldValue;
use Kalaka\Admin\FieldEdit\PreparedEdit;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Tests\Integration\Foundation\AdminProbe;

/** B5-1: staff correct one field value of a registration (`kalaka/edit-field-value`). */
final class EditFieldValueTest extends StaffEditTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->addRegistration($this->event, '00A0');
        $this->addValue($this->event, '00A0', 'name', 'Teszt Elek');
        $this->addValue($this->event, '00A0', 'tier', 'a');
        $this->addRegistration($this->event, '00A2');
    }

    /** @return list<array<string, mixed>> the changes of the last audit entry */
    private function changesOfLast(): array
    {
        $entries = $this->entries();
        $last = $entries[count($entries) - 1];

        return array_values($this->decode($last['changes']));
    }

    public function testAPlainEditWritesTheValueAuditsItWithTheReasonAndEvaluatesTheRegistration(): void
    {
        $result = $this->edit('00A0', 'Teszt Elemér', 'name');

        self::assertSame(['changed' => true, 'over_limit' => []], $result);
        self::assertSame('Teszt Elemér', $this->valueOf('00A0', 'name'));
        $entry = $this->lastEntry();
        self::assertSame('kalaka/edit-field-value', $entry['action']);
        self::assertSame('Elírás', $entry['reason']);
        $changes = [];
        foreach ($this->changesOfLast() as $change) {
            $changes[(string) $change['field']] = $change;
        }
        self::assertSame('Teszt Elek', $changes['field:name']['before']);
        self::assertSame('Teszt Elemér', $changes['field:name']['after']);
        self::assertContains('registration/' . $this->event . '/00A0', $this->subjectsOf($entry['id'] ?? 0));
        self::assertSame(1, $this->evaluated, 'The runner evaluates the touched registration.');
    }

    public function testAMissingOrEmptyReasonIsAccepted(): void
    {
        foreach ([null, ''] as $position => $reason) {
            $stored = 'Név ' . $position;
            $prepared = new PreparedEdit($this->event, $this->field('name'), $stored);
            $useCase = new EditFieldValue($prepared, $this->corrections());
            $input = ['event_id' => $this->event, 'code' => '00A0', 'field' => 'name', 'value' => $stored];
            $result = $this->exec($useCase, $reason === null ? $input : $input + ['reason' => $reason]);

            self::assertSame(['changed' => true, 'over_limit' => []], $result);
            self::assertSame($stored, $this->valueOf('00A0', 'name'));
        }
    }

    public function testAnIdenticalRepeatWritesNothingAndAnExpectedMismatchIsAVersionConflict(): void
    {
        self::assertSame(['changed' => false, 'over_limit' => []], $this->edit('00A0', 'Teszt Elek', 'name'));
        // The identical value is checked before `expected`.
        self::assertSame(
            ['changed' => false, 'over_limit' => []],
            $this->edit('00A0', 'Teszt Elek', 'name', ['expected' => 'régi'])
        );

        try {
            $this->edit('00A0', 'Teszt Elemér', 'name', ['expected' => 'régi']);
            self::fail('A value that changed since the screen showed it is a conflict.');
        } catch (VersionConflict) {
            self::assertSame('Teszt Elek', $this->valueOf('00A0', 'name'));
        }
        // `expected: null` means none was stored.
        $created = $this->edit('00A2', 'Új', 'name', ['expected' => null]);
        self::assertSame(['changed' => true, 'over_limit' => []], $created);
        self::assertSame('Új', $this->valueOf('00A2', 'name'));
        // Left out, the check is skipped.
        self::assertTrue($this->edit('00A2', 'Újabb', 'name')['changed']);
    }

    public function testClearingRemovesTheValueAndClearingAnEmptyFieldIsANoOp(): void
    {
        self::assertTrue($this->edit('00A0', null, 'name')['changed']);
        self::assertNull($this->valueOf('00A0', 'name'));

        self::assertFalse($this->edit('00A0', null, 'name')['changed']);
        self::assertFalse($this->edit('00A2', null, 'name')['changed']);
    }

    public function testAnEditForAnotherRegistrationOrAnotherFieldThanPreparedIsRefused(): void
    {
        $useCase = new EditFieldValue(new PreparedEdit($this->event, $this->field('name'), 'X'), $this->corrections());

        try {
            $this->exec($useCase, ['event_id' => $this->event, 'code' => '00A0', 'field' => 'tier', 'reason' => 'x']);
            self::fail('The prepared value belongs to another field.');
        } catch (SystemFailure) {
            self::assertSame('a', $this->valueOf('00A0', 'tier'));
        }
        $this->expectException(NotFound::class);
        $this->edit('00B0', 'X', 'name');
    }

    public function testChangingTheTierFreesTheOldSeatAndTakesTheNewOne(): void
    {
        $this->seat('00A0', $this->poolA);

        $result = $this->edit('00A0', 'b');

        self::assertSame(['changed' => true, 'over_limit' => []], $result);
        self::assertFalse($this->seats->holds($this->poolA, $this->key('00A0')));
        self::assertTrue($this->seats->holds($this->poolB, $this->key('00A0')));
        self::assertSame(0, $this->seats->held($this->poolA));
        self::assertSame(1, $this->seats->held($this->poolB));
    }

    public function testATakeOfAFullPoolGoesOverTheLimitAndNamesTheField(): void
    {
        $this->seat('00A0', $this->poolA);
        $this->addValue($this->event, '00A2', 'tier', 'b');
        $this->seat('00A2', $this->poolB);

        $result = $this->edit('00A0', 'b');

        self::assertSame(['changed' => true, 'over_limit' => ['tier']], $result);
        self::assertSame(1, $this->seats->over($this->poolB));
        self::assertSame(2, $this->seats->held($this->poolB));
        self::assertSame(0, $this->seats->held($this->poolA));
    }

    public function testAPreviewSaysWhatTheRunWouldDoAndChangesNothing(): void
    {
        $this->seat('00A0', $this->poolA);
        $this->addValue($this->event, '00A2', 'tier', 'b');
        $this->seat('00A2', $this->poolB);

        $entries = count($this->entries());
        $result = $this->edit('00A0', 'b', 'tier', ['preview' => true]);

        self::assertSame([
            'changed' => true,
            'over_limit' => ['tier'],
            'warnings' => [\Kalaka\Assignments\Api\AssignmentSets::FULL_WARNING],
        ], $result);
        self::assertSame('a', $this->valueOf('00A0', 'tier'));
        self::assertTrue($this->seats->holds($this->poolA, $this->key('00A0')));
        self::assertSame(1, $this->seats->held($this->poolB));
        $same = $this->edit('00A0', 'a', 'tier', ['preview' => true]);
        self::assertSame(['changed' => false, 'over_limit' => [], 'warnings' => []], $same);
        self::assertCount($entries, $this->entries(), 'A preview writes no audit entry.');
        // A real run is audited, an unchanged one too.
        $this->edit('00A0', 'b', 'tier');
        self::assertCount($entries + 1, $this->entries());
        $this->edit('00A0', 'b', 'tier');
        self::assertCount($entries + 2, $this->entries());
    }

    public function testAPreviewAndAnUnchangedRunLeaveTheRegistrationUnevaluated(): void
    {
        // A rule that would write a decision for any registration the runner evaluates.
        $this->rule->answer = 'on';

        $this->edit('00A0', 'b', 'tier', ['preview' => true]);
        $this->edit('00A0', 'a', 'tier');
        $this->edit('00A0', 'a', 'tier', ['preview' => true]);

        self::assertSame(0, $this->evaluated);
        self::assertSame(0, $this->decisionCount());

        // A real change touches the registration, so the runner evaluates it.
        $this->edit('00A0', 'b', 'tier');
        self::assertSame(1, $this->evaluated);
        self::assertSame(1, $this->decisionCount());
    }

    private function decisionCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_decisions");
    }

    public function testNoWarningForARegistrationThatWouldTakeNoSeat(): void
    {
        $this->addRegistration($this->event, '00A5', 'waitlisted');
        $this->addRegistration($this->event, '00A8', 'withdrawn');
        $this->addValue($this->event, '00A2', 'tier', 'b');
        $this->seat('00A2', $this->poolB);

        foreach (['00A5', '00A8'] as $code) {
            self::assertSame(
                ['changed' => true, 'over_limit' => [], 'warnings' => []],
                $this->edit($code, 'b', 'tier', ['preview' => true])
            );
            self::assertSame(['changed' => true, 'over_limit' => []], $this->edit($code, 'b'));
        }
    }

    public function testClearingTheTierFreesItsSeat(): void
    {
        $this->seat('00A0', $this->poolA);

        $this->edit('00A0', null);

        self::assertSame(0, $this->seats->held($this->poolA));
        self::assertNull($this->valueOf('00A0', 'tier'));
    }

    public function testAWaitlistedRegistrationHoldsNoSeatAndAWithdrawnOneKeepsNone(): void
    {
        $this->addRegistration($this->event, '00A5', 'waitlisted');
        $this->addValue($this->event, '00A5', 'tier', 'a');
        $this->addRegistration($this->event, '00A8', 'withdrawn');
        $this->addValue($this->event, '00A8', 'tier', 'a');

        self::assertTrue($this->edit('00A5', 'b')['changed']);
        self::assertTrue($this->edit('00A8', 'b')['changed']);

        self::assertSame('b', $this->valueOf('00A5', 'tier'));
        self::assertSame('b', $this->valueOf('00A8', 'tier'));
        self::assertSame(0, $this->seats->held($this->poolA));
        self::assertSame(0, $this->seats->held($this->poolB));
        self::assertSame(RegistrationStatus::Waitlisted, (new \Kalaka\Registrations\Api\Registrations())
            ->find($this->key('00A5'))?->status);
    }

    public function testAPlanThatWentStaleBeforeTheLockIsAVersionConflictAndNothingIsWritten(): void
    {
        $this->seat('00A0', $this->poolA);
        $slipped = false;
        $source = new TierClaimSource(['a' => $this->poolA, 'b' => $this->poolB], function () use (&$slipped): void {
            if (!$slipped) {
                $slipped = true;
                // Another write lands after the plan read the values and before the registration is locked.
                $this->addValue($this->event, '00A0', 'note', 'új');
            }
        });
        $useCase = new EditFieldValue(
            new PreparedEdit($this->event, $this->field(), 'b'),
            new \Kalaka\Registrations\Api\FieldCorrections([$source]),
        );

        try {
            $this->exec($useCase, ['event_id' => $this->event, 'code' => '00A0', 'field' => 'tier', 'reason' => 'x']);
            self::fail('The claims must be planned again.');
        } catch (VersionConflict) {
            self::assertSame('a', $this->valueOf('00A0', 'tier'));
            self::assertTrue($this->seats->holds($this->poolA, $this->key('00A0')));
        }
        // Planned again, it goes through.
        self::assertTrue($this->edit('00A0', 'b')['changed']);
    }

    public function testThePoolsToTakeAreLockedBeforeTheRegistration(): void
    {
        $corrections = $this->corrections();
        $plan = $corrections->plan($this->key('00A0'), 'tier', 'b');

        $this->inRun(function (array $input, Context $context) use ($corrections, $plan): void {
            $corrections->lockPools($plan, $context);
            $status = $corrections->lock($this->key('00A0'), $context);
            self::assertNotNull($status);
            $corrections->move($plan, $this->key('00A0'), $status, $context);
        });

        self::assertTrue($this->seats->holds($this->poolB, $this->key('00A0')));
    }

    public function testTheCheckRefusesARunThatDoesNotHoldTheRegistration(): void
    {
        $corrections = $this->corrections();
        $plan = $corrections->plan($this->key('00A0'), 'tier', 'b');

        $this->expectException(SystemFailure::class);
        $this->inRun(function (array $input, Context $context) use ($corrections, $plan): void {
            $corrections->check($context, $this->key('00A0'), $plan, 'tier', 'a', false, null);
        });
    }

    public function testATakeAfterTheRegistrationIsLockedWithoutLockPoolsFailsTheRun(): void
    {
        $corrections = $this->corrections();
        $plan = $corrections->plan($this->key('00A0'), 'tier', 'b');

        $this->expectException(SystemFailure::class);
        $this->inRun(function (array $input, Context $context) use ($corrections, $plan): void {
            $status = $corrections->lock($this->key('00A0'), $context);
            self::assertNotNull($status);
            $corrections->move($plan, $this->key('00A0'), $status, $context);
        });
    }

    public function testFullAccessPassesAndAGrantNeedsTheColumnReadWriteAndTheRegistrationInItsView(): void
    {
        $editor = $this->plainUser();
        $actor = new UserActor($editor);
        $writes = $this->viewWithColumns([
            ['column' => 'code', 'write' => false],
            ['column' => 'field:name', 'write' => true],
            ['column' => 'field:tier', 'write' => false],
        ], [['column' => 'field:tier', 'in' => ['a']]]);
        $this->saveGrant($this->event, $editor, ['view_id' => $writes, 'actions' => []]);

        // Read-write column, registration in the filter.
        self::assertTrue($this->edit('00A0', 'Más Név', 'name', [], $actor)['changed']);

        // A read-only column: refused, audited as failed, nothing written.
        try {
            $this->edit('00A0', 'b', 'tier', [], $actor);
            self::fail('A read-only column is not an edit right.');
        } catch (PermissionDenied) {
            self::assertSame('a', $this->valueOf('00A0', 'tier'));
        }
        $failed = $this->entries();
        $last = $failed[count($failed) - 1];
        self::assertSame('failed', $last['outcome']);
        self::assertNull($last['changes']);

        // The registration is outside the view's filter: the coarse check refuses.
        $this->expectException(PermissionDenied::class);
        $this->edit('00A2', 'Más', 'name', [], $actor);
    }

    public function testAnEditThatMovesTheRegistrationOutOfTheViewsFilterIsAllowed(): void
    {
        $editor = $this->plainUser();
        $view = $this->viewWithColumns(
            [['column' => 'code', 'write' => false], ['column' => 'field:name', 'write' => true]],
            [['column' => 'field:name', 'in' => ['Teszt Elek']]]
        );
        $this->saveGrant($this->event, $editor, ['view_id' => $view, 'actions' => []]);

        self::assertTrue($this->edit('00A0', 'Máshová', 'name', [], new UserActor($editor))['changed']);
        self::assertSame('Máshová', $this->valueOf('00A0', 'name'));
    }

    public function testTheFineCheckNeedsTheRegistrationLockedByTheRun(): void
    {
        $this->expectException(SystemFailure::class);
        $this->runner->run(new AdminProbe(
            fn (array $input, Context $context): bool => (new Access())
                ->mayWrite($this->admin, $this->key('00A0'), 'field:name', $context)
        ), [], $this->admin);
    }
}
