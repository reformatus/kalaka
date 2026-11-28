<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin\FieldEdit;

use Kalaka\Admin\Api\FieldNotStaffEditable;
use Kalaka\Admin\FieldEdit\FieldEditInput;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;

/** The part of a staff edit before the run: which fields staff may edit and the value in its stored form. */
final class FieldEditInputTest extends StaffEditTestCase
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
        $this->declare('name', 'kalaka/text-field');
        $this->declare('choice', 'kalaka/choice-field', 'form', $options);
        $this->declare('consent', 'kalaka/consent-field', 'staff');
        $this->declare('scale', 'kalaka/scale-field');
        $this->declare('born', 'kalaka/date-field');
        $this->declare('decision', 'kalaka/text-field', 'system');
        $this->declare('award', 'kalaka/text-field', 'extension');
        $this->declare('extra', 'kalaka/text-field', 'extension', null, true);
        $this->declare('old', 'kalaka/text-field', 'form', null, false, '2026-11-02 08:00:00.000000');
    }

    /** @param list<array{value: string, label: string, retired_at: ?string}>|null $options */
    private function declare(
        string $key,
        string $type,
        string $source = 'form',
        ?array $options = null,
        bool $writable = false,
        ?string $retired = null,
    ): void {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_participant_fields', [
            'event_id' => $this->event,
            'field_key' => $key,
            'version' => 1,
            'type' => $type,
            'title' => $key,
            'options' => $options === null ? null : wp_json_encode($options),
            'source' => $source,
            'retired_at' => $retired,
            'created_at' => '2026-11-01 07:00:00.000000',
            'updated_at' => '2026-11-01 07:00:00.000000',
            'position' => 1,
            'form_writable' => $writable ? 1 : 0,
        ]);
    }

    /** @param array<string, mixed> $more */
    private function stored(string $field, mixed $value, array $more = []): ?string
    {
        return (new FieldEditInput())->prepare(
            $more + [
                'event_id' => $this->event,
                'code' => '00A0',
                'field' => $field,
                'value' => $value,
                'reason' => 'Elírás',
            ],
            $this->admin
        )->stored;
    }

    public function testTheTypeTurnsTheValueIntoItsStoredFormAndABlankIsAClear(): void
    {
        self::assertSame('Új Név', $this->stored('name', '  Új Név '));
        self::assertNull($this->stored('name', null));
        self::assertNull($this->stored('choice', []));
        self::assertSame('a', $this->stored('choice', 'a'));
        self::assertSame('1', $this->stored('consent', '1'));
        self::assertSame('0', $this->stored('consent', '0'));
        self::assertSame('1', $this->stored('consent', true));
        self::assertSame('3', $this->stored('scale', '3'));
        self::assertSame('3', $this->stored('scale', 3));
    }

    public function testADateTakesTheStoredFormAndTheTypesOwnCheckRefusesTheRest(): void
    {
        self::assertSame('2010-05-17', $this->stored('born', '2010-05-17'));
        self::assertSame('2010-05-17', $this->stored('born', ' 2010-05-17 '));
        self::assertNull($this->stored('born', null));
        foreach (['2010-02-30', '17/05/2010', '2010-5-17', 'tegnap'] as $bad) {
            try {
                $this->stored('born', $bad);
                self::fail('A date that is not a real YYYY-MM-DD is refused: ' . $bad);
            } catch (InvalidInput) {
                self::assertTrue(true);
            }
        }
    }

    public function testABlankTextIsRefusedAndOnlyNullClears(): void
    {
        foreach (['', '   '] as $blank) {
            try {
                $this->stored('name', $blank);
                self::fail('A blank text is not a clear.');
            } catch (InvalidInput) {
                self::assertTrue(true);
            }
        }
    }

    public function testAChoiceTakesTheFieldsActiveOptionsOnly(): void
    {
        $before = count($this->entries());
        foreach (['c', 'zzz'] as $value) {
            try {
                $this->stored('choice', $value);
                self::fail('A retired or unknown option is not a value.');
            } catch (InvalidInput) {
                self::assertCount($before, $this->entries(), 'An invalid input is not audited.');
            }
        }
    }

    public function testStaffDoNotEditSystemFieldsFieldsOnlyTheirOwnActWritesRetiredFieldsOrUnknownKeys(): void
    {
        foreach (['decision', 'award', 'old'] as $field) {
            try {
                $this->stored($field, 'x');
                self::fail($field . ' is not staff-editable.');
            } catch (FieldNotStaffEditable) {
                self::assertTrue(true);
            }
        }
        self::assertSame('x', $this->stored('extra', 'x'));
        self::assertSame('1', $this->stored('consent', '1'));

        $this->expectException(NotFound::class);
        $this->stored('nincs', 'x');
    }

    public function testTheReasonIsOptionalAndAtMost500Characters(): void
    {
        try {
            $this->stored('name', 'X', ['reason' => str_repeat('é', 501)]);
            self::fail('The reason is at most 500 characters.');
        } catch (InvalidInput) {
            self::assertTrue(true);
        }
        foreach (['', '   ', null] as $reason) {
            self::assertSame('X', $this->stored('name', 'X', ['reason' => $reason]));
        }
        self::assertSame('X', $this->stored('name', 'X', ['reason' => str_repeat('é', 500)]));
    }
}
