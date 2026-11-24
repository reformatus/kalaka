<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin\FieldEdit;

use Kalaka\Admin\Api\RegistrationDetail;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\VersionConflict;

/** B5-2: what the detail read says about editing each field (`writable`, `edit`). */
final class RegistrationDetailEditTest extends StaffEditTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $options = [
            ['value' => 'a', 'label' => 'A', 'retired_at' => null],
            ['value' => 'b', 'label' => 'B', 'retired_at' => null],
            ['value' => 'c', 'label' => 'C', 'retired_at' => '2026-11-01 08:00:00.000000'],
        ];
        $this->declareField('name', 'kalaka/text-field');
        $this->declareField(TierClaimSource::FIELD, 'kalaka/choice-field', 'form', $options);
        $this->declareField('consent', 'kalaka/consent-field', 'staff');
        $this->declareField('decision', 'kalaka/text-field', 'system');
        $this->declareField('award', 'kalaka/text-field', 'extension');
        $this->declareField('extra', 'kalaka/text-field', 'extension', null, true);
        $this->declareField('old', 'kalaka/text-field', 'form', null, false, '2026-11-02 08:00:00.000000');

        $this->addRegistration($this->event, '00A0');
        $this->addValue($this->event, '00A0', 'name', 'Teszt Elek');
        $this->addValue($this->event, '00A0', 'tier', 'a');
        $this->addValue($this->event, '00A0', 'award', '5000');
        $this->addValue($this->event, '00A0', 'old', 'régi');
        $this->seat('00A0', $this->poolA);
        // Another registration holds the one seat of pool B.
        $this->addRegistration($this->event, '00A2');
        $this->addValue($this->event, '00A2', 'tier', 'b');
        $this->seat('00A2', $this->poolB);
    }

    /** @return array<string, mixed> */
    private function read(?Actor $actor = null, string $code = '00A0'): array
    {
        return (new RegistrationDetail(corrections: $this->corrections()))
            ->of($actor ?? $this->admin, $this->event, $code);
    }

    /** @return array<string, array<string, mixed>> the detail's fields by key */
    private function fields(?Actor $actor = null, string $code = '00A0'): array
    {
        $fields = [];
        foreach ($this->read($actor, $code)['fields'] as $field) {
            $fields[(string) $field['key']] = $field;
        }

        return $fields;
    }

    /** @param list<array<string, mixed>> $columns */
    private function holder(array $columns): UserActor
    {
        $user = $this->plainUser();
        $view = $this->viewWithColumns($columns, []);
        $this->saveGrant($this->event, $user, ['view_id' => $view, 'actions' => []]);

        return new UserActor($user);
    }

    public function testFullAccessMayWriteEveryFieldStaffEditAndNoOther(): void
    {
        $fields = $this->fields();

        self::assertArrayNotHasKey('decision', $fields, 'A system field is not in the read.');
        foreach (['name', 'tier', 'consent', 'extra'] as $key) {
            self::assertTrue($fields[$key]['writable'], $key);
            self::assertArrayHasKey('edit', $fields[$key], $key);
        }
        // An extension field only its own act writes, and a retired field, are shown but never writable.
        foreach (['award', 'old'] as $key) {
            self::assertFalse($fields[$key]['writable'], $key);
            self::assertArrayNotHasKey('edit', $fields[$key], $key);
        }
    }

    public function testAReadWriteGrantMakesItsWritableColumnsWritableAndAReadOnlyOneNone(): void
    {
        $writer = $this->holder([
            ['column' => 'code', 'write' => false],
            ['column' => 'field:name', 'write' => true],
            ['column' => 'field:tier', 'write' => false],
            ['column' => 'field:consent', 'write' => true],
            ['column' => 'field:award', 'write' => true],
        ]);
        $fields = $this->fields($writer);

        self::assertTrue($fields['name']['writable']);
        self::assertTrue($fields['consent']['writable']);
        self::assertFalse($fields['tier']['writable'], 'A read-only column is not writable.');
        self::assertFalse($fields['award']['writable'], 'A read-write column of a field staff do not edit.');
        self::assertArrayNotHasKey('extra', $fields, 'A column the view does not list is not in the read.');

        $reader = $this->holder([
            ['column' => 'code', 'write' => false],
            ['column' => 'field:name', 'write' => false],
            ['column' => 'field:tier', 'write' => false],
        ]);
        foreach ($this->fields($reader) as $key => $field) {
            self::assertFalse($field['writable'], $key);
            self::assertArrayNotHasKey('edit', $field, $key);
        }
    }

    public function testAWriteColumnOfAViewThatDoesNotHoldTheRegistrationIsNotWritable(): void
    {
        $user = $this->plainUser();
        $view = $this->viewWithColumns(
            [['column' => 'code', 'write' => false], ['column' => 'field:name', 'write' => true]],
            [['column' => 'field:name', 'in' => ['Teszt Elek']]]
        );
        $this->saveGrant($this->event, $user, ['view_id' => $view, 'actions' => []]);
        $this->addRegistration($this->event, '00A5');
        $this->addValue($this->event, '00A5', 'name', 'Teszt Elek');

        self::assertTrue($this->fields(new UserActor($user), '00A5')['name']['writable']);
    }

    public function testTheEditCarriesTheEditorKindTheAnswerAndTheStoredValueAsTheActComparesIt(): void
    {
        $fields = $this->fields();

        self::assertSame(
            ['answer' => ['kind' => 'kalaka/text-field', 'value' => 'Teszt Elek'], 'expected' => 'Teszt Elek'],
            $fields['name']['edit']
        );
        self::assertSame('kalaka/choice-field', $fields['tier']['edit']['answer']['kind']);
        self::assertSame('a', $fields['tier']['edit']['answer']['value']);
        self::assertSame('a', $fields['tier']['edit']['expected']);
        // A field with no stored value opens empty and expects none.
        self::assertSame(
            ['answer' => ['kind' => 'kalaka/consent-field', 'value' => null], 'expected' => null, 'full' => false],
            $fields['consent']['edit']
        );
    }

    public function testExpectedGoesBackUnchangedAndAChangedValueIsAConflict(): void
    {
        $expected = $this->fields()['name']['edit']['expected'];
        self::assertSame(['changed' => true, 'over_limit' => []], $this->edit('00A0', 'Új Név', 'name', [
            'expected' => $expected,
        ]));

        // The screen opened before that write still sends the old value back.
        $this->expectException(VersionConflict::class);
        $this->edit('00A0', 'Másik', 'name', ['expected' => $expected]);
    }

    public function testExpectedOfAFieldWithNoValueIsNullAndTheActTakesIt(): void
    {
        $edit = $this->fields()['consent']['edit'];
        self::assertNull($edit['expected']);

        self::assertTrue($this->edit('00A0', '1', 'consent', ['expected' => $edit['expected']])['changed']);
    }

    public function testTheOptionsAreTheActiveOnesAndFullMeansTheirPoolWouldGoOverForThisRegistration(): void
    {
        $edit = $this->fields()['tier']['edit'];

        self::assertSame(
            [
                ['value' => 'a', 'label' => 'A', 'full' => false],
                ['value' => 'b', 'label' => 'B', 'full' => true],
            ],
            $edit['options']
        );
        // The registration holding the seat of B sees its own option as not full, and A (limit 2, one held) not full.
        self::assertSame(
            [false, false],
            array_column($this->fields($this->admin, '00A2')['tier']['edit']['options'], 'full')
        );
        // The type's own option list is not offered where there is none.
        self::assertArrayNotHasKey('options', $this->fields()['name']['edit']);
    }

    public function testFullIsTrueOnlyForARegisteredRegistration(): void
    {
        foreach (['waitlisted', 'withdrawn', 'removed'] as $n => $status) {
            $code = ['00A5', '00A8', '01A1'][$n];
            $this->addRegistration($this->event, $code, $status);
            $this->addValue($this->event, $code, 'tier', 'a');

            self::assertSame(
                [false, false],
                array_column($this->fields($this->admin, $code)['tier']['edit']['options'], 'full'),
                $status
            );
        }
    }

    public function testReadingTheEditWritesNoAuditEntryAndMovesNoSeat(): void
    {
        $entries = count($this->entries());
        $held = [$this->seats->held($this->poolA), $this->seats->held($this->poolB)];

        $this->read();
        $this->read(null, '00A2');

        self::assertCount($entries, $this->entries());
        self::assertSame($held, [$this->seats->held($this->poolA), $this->seats->held($this->poolB)]);
    }
}
