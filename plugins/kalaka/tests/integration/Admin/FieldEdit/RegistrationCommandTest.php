<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin\FieldEdit;

use Kalaka\Admin\Cli\RegistrationCommand;
use Kalaka\Assignments\Api\AssignmentSets;

/** `wp kalaka registration set-field`: the wrapper's warnings, confirmation, flags and permission check. */
final class RegistrationCommandTest extends StaffEditTestCase
{
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        // WP-CLI is not loaded in PHPUnit: a recording double stands in for it.
        require_once __DIR__ . '/../../Foundation/Seed/WpCliDouble.php';
        if (defined('WP_CLI::IS_TEST_DOUBLE')) {
            \WP_CLI::reset();
        }
        global $wpdb;
        $options = wp_json_encode([
            ['value' => 'a', 'label' => 'A', 'retired_at' => null],
            ['value' => 'b', 'label' => 'B', 'retired_at' => null],
        ]);
        $wpdb->insert($wpdb->prefix . 'kalaka_participant_fields', [
            'event_id' => $this->event,
            'field_key' => 'tier',
            'version' => 1,
            'type' => 'kalaka/choice-field',
            'title' => 'tier',
            'options' => $options,
            'source' => 'form',
            'created_at' => '2026-11-01 07:00:00.000000',
            'updated_at' => '2026-11-01 07:00:00.000000',
            'position' => 1,
            'form_writable' => 0,
        ]);
        $this->addRegistration($this->event, '00A0');
        $this->addValue($this->event, '00A0', 'tier', 'a');
        $this->addRegistration($this->event, '00A2');
        $this->addValue($this->event, '00A2', 'tier', 'b');
        $this->seat('00A0', $this->poolA);
        $this->seat('00A2', $this->poolB);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        wp_set_current_user(0);

        parent::tear_down();
    }

    private function command(): RegistrationCommand
    {
        return new RegistrationCommand($this->runner, $this->corrections());
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    private function setField(array $args, array $assoc): void
    {
        $this->command()->setField(
            array_merge([(string) $this->event], $args),
            $assoc + ['reason' => 'Elírás']
        );
    }

    /** @return list<string> */
    private function kinds(): array
    {
        return array_map(static fn (array $call): string => $call[0], \WP_CLI::$calls);
    }

    public function testItWarnsWhenTheChoiceIsFullAsksAndThenSetsIt(): void
    {
        $this->setField(['00A0', 'tier', 'b'], []);

        self::assertSame(['warning', 'confirm', 'success'], $this->kinds());
        self::assertSame(AssignmentSets::FULL_WARNING, \WP_CLI::$calls[0][1]);
        self::assertSame('b', $this->valueOf('00A0', 'tier'));
        self::assertSame(1, $this->seats->over($this->poolB));
    }

    public function testWithoutAWarningItStillAsksAndADeclineStopsTheCommand(): void
    {
        // 00A2 moves from b to a, a pool with room: no warning, but the question is still asked.
        \WP_CLI::$decline = true;
        try {
            $this->setField(['00A2', 'tier', 'a'], []);
            self::fail('A declined confirmation stops the command.');
        } catch (\RuntimeException) {
            self::assertSame('b', $this->valueOf('00A2', 'tier'));
        }
        self::assertSame(['confirm', 'error'], $this->kinds());
        self::assertSame(1, $this->seats->held($this->poolB));
    }

    public function testYesSkipsTheQuestion(): void
    {
        \WP_CLI::$decline = true;

        $this->setField(['00A2', 'tier', 'a'], ['yes' => true]);

        self::assertSame('a', $this->valueOf('00A2', 'tier'));
        self::assertSame(0, $this->seats->held($this->poolB));
    }

    public function testNoWarningForARegistrationThatWouldTakeNoSeat(): void
    {
        $this->addRegistration($this->event, '00A5', 'withdrawn');
        $this->addValue($this->event, '00A5', 'tier', 'a');

        $this->setField(['00A5', 'tier', 'b'], ['yes' => true]);

        self::assertSame(['success'], array_values(array_filter(
            $this->kinds(),
            static fn (string $kind): bool => $kind !== 'confirm'
        )));
        self::assertSame('b', $this->valueOf('00A5', 'tier'));
        self::assertSame(1, $this->seats->held($this->poolB));
    }

    public function testClearTakesNoValueAndRemovesTheStoredOne(): void
    {
        $this->setField(['00A0', 'tier'], ['clear' => true, 'yes' => true]);

        self::assertNull($this->valueOf('00A0', 'tier'));
        self::assertSame(0, $this->seats->held($this->poolA));
        self::assertSame('success', end(\WP_CLI::$calls)[0]);
    }

    public function testExpectedIsCheckedAndAnIdenticalValueIsReportedAsNothingToChange(): void
    {
        try {
            $this->setField(['00A0', 'tier', 'b'], ['expected' => 'b', 'yes' => true]);
            self::fail('The stored value is not the expected one.');
        } catch (\RuntimeException) {
            self::assertSame('a', $this->valueOf('00A0', 'tier'));
        }
        \WP_CLI::reset();

        $this->setField(['00A0', 'tier', 'a'], ['expected' => 'a', 'yes' => true]);
        self::assertStringStartsWith('Nothing to change', (string) end(\WP_CLI::$calls)[1]);
    }

    public function testTheValueOrClearIsRequired(): void
    {
        foreach ([[['00A0', 'tier'], []], [['00A0', 'tier', 'a'], ['clear' => true]]] as [$args, $assoc]) {
            try {
                $this->setField($args, $assoc);
                self::fail('The value or --clear is required, not both.');
            } catch (\RuntimeException) {
                self::assertSame('error', end(\WP_CLI::$calls)[0]);
            }
        }
    }

    public function testTheReasonIsOptional(): void
    {
        $this->command()->setField([(string) $this->event, '00A0', 'tier', 'b'], []);
        self::assertSame('b', $this->valueOf('00A0', 'tier'));
    }

    public function testABlankValueIsRefusedUnlessClearIsGiven(): void
    {
        foreach (['', '   '] as $blank) {
            try {
                $this->setField(['00A0', 'tier', $blank], ['yes' => true]);
                self::fail('A blank value must not clear the field.');
            } catch (\RuntimeException) {
                self::assertSame('a', $this->valueOf('00A0', 'tier'));
                self::assertTrue($this->seats->holds($this->poolA, $this->key('00A0')));
            }
        }
        \WP_CLI::reset();

        $this->setField(['00A0', 'tier'], ['clear' => true, 'yes' => true]);

        self::assertNull($this->valueOf('00A0', 'tier'));
    }

    public function testAGrantWithoutTheColumnGetsTheSameRefusalForAnExistingAndAMissingField(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_participant_fields', [
            'event_id' => $this->event,
            'field_key' => 'name',
            'version' => 1,
            'type' => 'kalaka/text-field',
            'title' => 'name',
            'source' => 'form',
            'created_at' => '2026-11-01 07:00:00.000000',
            'updated_at' => '2026-11-01 07:00:00.000000',
            'position' => 2,
            'form_writable' => 0,
        ]);
        $editor = self::factory()->user->create(['role' => 'editor']);
        $view = $this->viewWithColumns(
            [['column' => 'field:tier', 'write' => true], ['column' => 'field:name', 'write' => false]],
            [['column' => 'field:tier', 'in' => ['a']]]
        );
        $this->saveGrant($this->event, $editor, ['view_id' => $view, 'actions' => []]);
        wp_set_current_user($editor);

        $messages = [];
        foreach (['name', 'nincs'] as $field) {
            \WP_CLI::reset();
            try {
                $this->setField(['00A0', $field, 'x'], ['yes' => true]);
                self::fail('The user may not write that column.');
            } catch (\RuntimeException) {
                self::assertSame(['error'], $this->kinds());
                $messages[] = \WP_CLI::$calls[0][1][0];
            }
        }

        self::assertCount(1, array_unique($messages));
    }

    public function testARefusedUserLearnsNothingWhetherTheFieldOrTheRegistrationExists(): void
    {
        wp_set_current_user(self::factory()->user->create());
        $messages = [];
        $cases = [['00A0', 'tier'], ['00A0', 'nincs'], ['00A0', 'name'], ['00B0', 'tier'], ['00B0', 'nincs']];
        foreach ($cases as $args) {
            \WP_CLI::reset();
            try {
                $this->setField([$args[0], $args[1], 'b'], ['yes' => true]);
                self::fail('The command should have refused the user.');
            } catch (\RuntimeException) {
                self::assertSame(['error'], $this->kinds());
                $messages[] = \WP_CLI::$calls[0][1][0];
            }
        }

        self::assertCount(1, array_unique($messages), 'The refusal must not depend on what exists.');
        self::assertSame('a', $this->valueOf('00A0', 'tier'));
    }

    public function testTheCommandRunsAsTheCurrentUserAndRefusesWhatTheirViewDoesNotHold(): void
    {
        $editor = self::factory()->user->create(['role' => 'editor']);
        $view = $this->viewWithColumns(
            [['column' => 'code', 'write' => false], ['column' => 'field:tier', 'write' => true]],
            [['column' => 'field:tier', 'in' => ['a']]]
        );
        $this->saveGrant($this->event, $editor, ['view_id' => $view, 'actions' => []]);
        wp_set_current_user($editor);

        // Inside the view, with the column read-write: allowed, and the audit says who did it.
        $this->setField(['00A0', 'tier', 'b'], ['yes' => true]);
        self::assertSame('b', $this->valueOf('00A0', 'tier'));
        $edits = array_values(array_filter(
            $this->entries(),
            static fn (array $entry): bool => $entry['action'] === 'kalaka/edit-field-value'
        ));
        self::assertNotSame([], $edits);
        foreach ($edits as $entry) {
            self::assertSame((string) $editor, $entry['actor_user_id']);
        }

        // 00A2 holds tier b: outside the view's filter. Full access would have allowed it.
        \WP_CLI::reset();
        try {
            $this->setField(['00A2', 'tier', 'a'], ['yes' => true]);
            self::fail('The user\'s view does not hold the registration.');
        } catch (\RuntimeException) {
            self::assertSame('b', $this->valueOf('00A2', 'tier'));
        }
    }
}
