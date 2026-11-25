<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin\FieldEdit;

use Kalaka\Admin\Api\Access;
use Kalaka\Assignments\Api\AssignmentSets;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\UseCase\Runner;

/** B5-2: `kalaka/edit-field-value` as an ability, prepared per call from its input. */
final class EditFieldValueAbilityTest extends StaffEditTestCase
{
    private Runner $production;

    private Contributions $contributions;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        // The ability runs the production runner; it joins the harness's open transaction here.
        $this->production = Runner::current();
        $this->contributions = Contributions::current();
        Runner::publish($this->runner);
        Access::forget();
        $this->declareField('name', 'kalaka/text-field');
        $this->declareField('consent', 'kalaka/consent-field', 'staff');
        $this->declareField('decision', 'kalaka/text-field', 'system');
        $this->addRegistration($this->event, '00A0');
        $this->addValue($this->event, '00A0', 'name', 'Teszt Elek');
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        Runner::publish($this->production);
        Contributions::record($this->contributions);
        wp_set_current_user(0);
        Access::forget();

        parent::tear_down();
    }

    /**
     * @param array<string, mixed> $more
     * @return array<string, mixed>
     */
    private function input(string $field, mixed $value, array $more = []): array
    {
        return $more + [
            'event_id' => $this->event,
            'code' => '00A0',
            'field' => $field,
            'value' => $value,
            'reason' => 'Elírás',
        ];
    }

    private function call(array $input): mixed
    {
        $ability = wp_get_ability('kalaka/edit-field-value');
        self::assertInstanceOf(\WP_Ability::class, $ability);

        return $ability->execute($input);
    }

    public function testTheAbilityIsRegisteredWithTheUseCasesSchema(): void
    {
        $ability = wp_get_ability('kalaka/edit-field-value');

        self::assertInstanceOf(\WP_Ability::class, $ability);
        self::assertSame('kalaka', $ability->get_category());
        self::assertSame((new \Kalaka\Admin\FieldEdit\EditFieldValue())->inputSchema(), $ability->get_input_schema());
        self::assertTrue($ability->get_meta()['show_in_rest']);
    }

    public function testAPreviewReportsWhatTheSaveWouldDoAndWritesNothing(): void
    {
        wp_set_current_user($this->admin->userId);

        $result = $this->call($this->input('name', 'Új Név', ['preview' => true]));

        self::assertSame(['changed' => true, 'over_limit' => [], 'warnings' => []], $result);
        self::assertSame('Teszt Elek', $this->valueOf('00A0', 'name'));
    }

    public function testASaveWritesTheValueAndReturnsTheActsOutput(): void
    {
        wp_set_current_user($this->admin->userId);

        $result = $this->call($this->input('name', 'Új Név', ['expected' => 'Teszt Elek']));

        self::assertSame(['changed' => true, 'over_limit' => []], $result);
        self::assertSame('Új Név', $this->valueOf('00A0', 'name'));
        $entry = $this->lastEntry();
        self::assertSame('kalaka/edit-field-value', $entry['action']);
        self::assertSame('Elírás', $entry['reason']);
        // The same value again is nothing to do.
        self::assertSame(['changed' => false, 'over_limit' => []], $this->call($this->input('name', 'Új Név')));
    }

    public function testAStaleExpectedIsA409VersionConflictAndAnythingTheTypeRefusesIsAnInvalidInput(): void
    {
        wp_set_current_user($this->admin->userId);

        $conflict = $this->call($this->input('name', 'Új Név', ['expected' => 'régi']));
        self::assertInstanceOf(\WP_Error::class, $conflict);
        self::assertSame('kalaka_version_conflict', $conflict->get_error_code());
        self::assertSame(409, $conflict->get_error_data()['status']);
        self::assertSame('Teszt Elek', $this->valueOf('00A0', 'name'));

        $before = count($this->entries());
        $invalid = $this->call($this->input('consent', 'talán'));
        self::assertInstanceOf(\WP_Error::class, $invalid);
        self::assertSame(400, $invalid->get_error_data()['status']);
        self::assertCount($before, $this->entries(), 'A refused input is not audited.');
    }

    public function testAPreviewWritesNoAuditEntryAndAnUnchangedSaveStillDoes(): void
    {
        wp_set_current_user($this->admin->userId);
        $entries = count($this->entries());

        self::assertSame(
            ['changed' => true, 'over_limit' => [], 'warnings' => []],
            $this->call($this->input('name', 'Új Név', ['preview' => true]))
        );
        self::assertCount($entries, $this->entries());

        self::assertSame(['changed' => true, 'over_limit' => []], $this->call($this->input('name', 'Új Név')));
        self::assertCount($entries + 1, $this->entries());
        // The same value again changes nothing, and the real run is audited all the same.
        self::assertSame(['changed' => false, 'over_limit' => []], $this->call($this->input('name', 'Új Név')));
        self::assertCount($entries + 2, $this->entries());
    }

    public function testAPreviewNeedsNoReasonButASaveDoes(): void
    {
        wp_set_current_user($this->admin->userId);
        $without = $this->input('name', 'Új Név');
        unset($without['reason']);

        foreach ([$without, ['reason' => ''] + $without, ['reason' => '  '] + $without] as $input) {
            $entries = count($this->entries());
            self::assertSame(
                ['changed' => true, 'over_limit' => [], 'warnings' => []],
                $this->call($input + ['preview' => true])
            );
            self::assertCount($entries, $this->entries(), 'A preview writes no audit entry.');

            $save = $this->call($input);
            self::assertInstanceOf(\WP_Error::class, $save);
            self::assertSame(400, $save->get_error_data()['status']);
        }
        self::assertSame('Teszt Elek', $this->valueOf('00A0', 'name'));
        // A too long reason is refused for a preview as well.
        $long = $this->call($this->input('name', 'Új Név', ['preview' => true, 'reason' => str_repeat('a', 501)]));
        self::assertInstanceOf(\WP_Error::class, $long);
    }

    public function testASystemFieldIsRefused(): void
    {
        wp_set_current_user($this->admin->userId);

        $result = $this->call($this->input('decision', 'x'));

        self::assertInstanceOf(\WP_Error::class, $result);
    }

    public function testAReadOnlyGrantIsRefusedAndAReadWriteOneSaves(): void
    {
        $user = $this->plainUser();
        $view = $this->viewWithColumns([
            ['column' => 'code', 'write' => false],
            ['column' => 'field:name', 'write' => true],
            ['column' => 'field:consent', 'write' => false],
        ], []);
        $this->saveGrant($this->event, $user, ['view_id' => $view, 'actions' => []]);
        Access::forget();
        wp_set_current_user($user);

        $refused = $this->call($this->input('consent', '1'));
        self::assertInstanceOf(\WP_Error::class, $refused);
        self::assertSame(403, $refused->get_error_data()['status']);
        self::assertNull($this->valueOf('00A0', 'consent'));

        self::assertSame(['changed' => true, 'over_limit' => []], $this->call($this->input('name', 'Más')));
    }

    public function testThePreviewsWarningIsTheApprovedFullText(): void
    {
        self::assertSame(
            'Ez a lehetőség már betelt. Ha ide sorolod a jelentkezőt, a létszám túllépi a keretet.',
            AssignmentSets::FULL_WARNING
        );

        // A preview through the ability that would overfill a pool carries exactly that text.
        $contributions = clone Contributions::current();
        $contributions->seatClaimSource(new TierClaimSource(['a' => $this->poolA, 'b' => $this->poolB]));
        Contributions::record($contributions);
        $this->declareField(TierClaimSource::FIELD, 'kalaka/choice-field', 'form', [
            ['value' => 'a', 'label' => 'A', 'retired_at' => null],
            ['value' => 'b', 'label' => 'B', 'retired_at' => null],
        ]);
        $this->addValue($this->event, '00A0', 'tier', 'a');
        $this->seat('00A0', $this->poolA);
        $this->addRegistration($this->event, '00A2');
        $this->addValue($this->event, '00A2', 'tier', 'b');
        $this->seat('00A2', $this->poolB);
        wp_set_current_user($this->admin->userId);

        $result = $this->call($this->input(TierClaimSource::FIELD, 'b', ['preview' => true]));

        self::assertIsArray($result);
        self::assertSame([AssignmentSets::FULL_WARNING], $result['warnings']);
        self::assertSame(['tier'], $result['over_limit']);
    }
}
