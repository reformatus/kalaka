<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Assignments;

use Kalaka\Assignments\Api\AssignmentSets;
use Kalaka\Assignments\Api\AssignmentSource;
use Kalaka\Assignments\Api\AssignmentState;
use Kalaka\Assignments\PlaceRegistration;
use Kalaka\Events\CreateEvent;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\ParticipantFields\DeclareParticipantFields;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Registrations\RegistrationsTestCase;

/**
 * S44: request-mode assignment sets, their options with eligibility conditions in the one format, the tie
 * to the one request field (S44-1), and staff placing a registration by hand (an audited, confirmed fact,
 * at most one per set).
 */
final class AssignmentsTest extends RegistrationsTestCase
{
    private AssignmentSets $sets;

    private int $event;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        update_option('timezone_string', 'Europe/Budapest');
        $this->sets = new AssignmentSets($this->runner);
        $this->event = (int) $this->runner->run(new CreateEvent(), ['name' => 'Tábor'], new CliActor(null));
        $this->runner->run(new DeclareParticipantFields(), ['event_id' => $this->event, 'fields' => [[
            'key' => 'szolgalati_terulet',
            'type' => 'kalaka/choice-field',
            'title' => 'Szolgálati terület',
            'options' => [
                ['value' => 'ifivezeto', 'label' => 'Ifivezető'],
                ['value' => 'iskolamisszio', 'label' => 'Iskolamisszió'],
                ['value' => 'pro', 'label' => 'PRO'],
            ],
            'latest' => true,
        ]]], new CliActor(null));
    }

    /** @param array<string, mixed> $changes */
    private function newSet(array $changes = []): int
    {
        return $this->sets->save($changes + [
            'event_id' => $this->event,
            'name' => 'Modulok',
            'required' => true,
            'window_opens_at' => '2026-12-01 08:00',
            'window_closes_at' => '2027-02-15 20:00',
            'published' => false,
        ], new CliActor(null));
    }

    /**
     * @param array<string, mixed>|null $condition
     * @param array<string, mixed> $changes
     */
    private function newOption(int $set, string $label, ?array $condition = null, array $changes = []): string
    {
        return $this->sets->saveOption($changes + [
            'set_id' => $set,
            'label' => $label,
            'capacity' => 30,
            'leaders' => 'Teszt Vezető',
            'place' => 'Nagyterem',
            'condition' => $condition,
            'position' => 0,
        ], new CliActor(null));
    }

    /** @return array<string, mixed> */
    private static function trackIs(string $track): array
    {
        return [
            'match' => 'all',
            'items' => [['key' => 'szolgalati_terulet', 'operator' => 'equals', 'value' => $track]],
        ];
    }

    public function testASetIsStoredWithItsOptionsInTheirOrderAndItsWindowInUtc(): void
    {
        $set = $this->newSet(['time_slot' => 'Délelőtt']);
        self::assertSame('pro', $this->newOption($set, 'PRO', self::trackIs('pro'), ['position' => 8]));
        self::assertSame('modul_a', $this->newOption($set, 'Modul A', null, ['position' => 1]));
        self::assertSame('iskolamisszio', $this->newOption($set, 'Iskolamisszió', self::trackIs('iskolamisszio'), [
            'position' => 7,
        ]));

        $stored = $this->sets->find($set);
        self::assertNotNull($stored);
        self::assertSame('Modulok', $stored->name);
        self::assertSame('Délelőtt', $stored->timeSlot);
        self::assertTrue($stored->required);
        self::assertFalse($stored->published);
        self::assertNull($stored->requestFieldKey);
        self::assertSame('2026-12-01 07:00', $stored->windowOpens?->format('Y-m-d H:i'));
        self::assertSame(['modul_a', 'iskolamisszio', 'pro'], array_map(static fn ($o) => $o->key, $stored->options));
        self::assertSame(self::trackIs('pro'), $stored->option('pro')?->availabilityCondition());
        self::assertNull($stored->option('modul_a')?->availabilityCondition());
        self::assertSame([$set], array_map(static fn ($s) => $s->id, $this->sets->ofEvent($this->event)));
    }

    public function testTheWindowHoldsFromItsOpeningUpToItsClosing(): void
    {
        $set = $this->sets->find($this->newSet());
        self::assertNotNull($set);
        self::assertFalse($set->windowContains($this->utc('2026-12-01 06:59:59')));
        self::assertTrue($set->windowContains($this->utc('2026-12-01 07:00:00')));
        self::assertTrue($set->windowContains($this->utc('2027-02-15 18:59:59')));
        self::assertFalse($set->windowContains($this->utc('2027-02-15 19:00:00')));
    }

    public function testAnOptionKeyIsDerivedOnceAndNeverChanges(): void
    {
        $set = $this->newSet();
        self::assertSame('modul_a', $this->newOption($set, 'Modul A'));
        self::assertSame('modul_a_1', $this->newOption($set, 'Modul A'));

        $this->newOption($set, 'Modul Á, átnevezve', null, ['key' => 'modul_a', 'version' => 1]);
        self::assertSame('Modul Á, átnevezve', $this->sets->find($set)?->option('modul_a')?->label);
    }

    public function testAnOptionUpdateNeedsTheVersionItWasReadAt(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A');

        $this->expectException(VersionConflict::class);
        $this->newOption($set, 'Modul A', null, ['key' => 'modul_a']);
    }

    public function testAConditionReadingAKeyTheEventDoesNotHaveIsRefused(): void
    {
        $set = $this->newSet();

        $this->expectException(InvalidInput::class);
        $this->newOption($set, 'PRO', ['match' => 'all', 'items' => [
            ['key' => 'nincs_ilyen', 'operator' => 'equals', 'value' => 'pro'],
        ]]);
    }

    public function testASetOfAMissingEventIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->newSet(['event_id' => $this->event + 1000]);
    }

    public function testAWindowThatClosesBeforeItOpensIsRefused(): void
    {
        $this->expectException(InvalidInput::class);
        $this->newSet(['window_opens_at' => '2027-03-01 08:00']);
    }

    public function testTheFirstRequestFieldIsTiedAndTheSameOneAgainChangesNothing(): void
    {
        $set = $this->newSet();
        $this->sets->tieRequestField($set, 'modulok', new CliActor(null));
        $this->sets->tieRequestField($set, 'modulok', new CliActor(null));

        $stored = $this->sets->find($set);
        self::assertSame('modulok', $stored?->requestFieldKey);
        self::assertSame(2, $stored->version);
    }

    public function testASecondDifferentRequestFieldIsRefused(): void
    {
        $set = $this->newSet();
        $this->sets->tieRequestField($set, 'modulok', new CliActor(null));

        $this->expectException(RuleViolation::class);
        $this->sets->tieRequestField($set, 'modulok_2', new CliActor(null));
    }

    public function testOneFieldIsTheRequestFieldOfOneSetOnly(): void
    {
        $first = $this->newSet();
        $second = $this->newSet(['name' => 'Délutáni modulok']);
        $this->sets->tieRequestField($first, 'modulok', new CliActor(null));

        $this->expectException(RuleViolation::class);
        $this->sets->tieRequestField($second, 'modulok', new CliActor(null));
    }

    public function testStaffPlaceARegistrationAsAConfirmedFactAuditedOnTheRegistration(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A');
        $this->newOption($set, 'PRO', self::trackIs('pro'));
        $key = new RegistrationKey($this->event, '00A0');
        $this->seedRegistrations($key);

        // C6: staff place by hand; eligibility is not checked (the registration has no track at all).
        $this->place($key, $set, 'pro');

        $assignment = $this->sets->assignment($key, $set);
        self::assertSame('pro', $assignment?->optionKey);
        self::assertSame(AssignmentSource::Staff, $assignment->source);
        self::assertSame(AssignmentState::Confirmed, $assignment->state);

        $entries = $this->entries();
        $entry = $entries[array_key_last($entries)];
        self::assertSame('kalaka/place-registration', $entry['action']);
        self::assertContains('registration/' . $this->event . '/00A0', $this->subjectsOf((int) $entry['id']));
        $fields = array_column($this->decode($entry['changes']), 'field');
        self::assertContains('assignment:' . $set . '.option_key', $fields);
    }

    public function testPlacingAgainOverridesTheOneAssignmentOfTheSet(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A');
        $this->newOption($set, 'Modul B');
        $key = new RegistrationKey($this->event, '00A0');
        $this->seedRegistrations($key);

        $this->place($key, $set, 'modul_a');
        $this->place($key, $set, 'modul_b');

        global $wpdb;
        self::assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_assignments WHERE event_id = %d",
            $this->event
        )));
        self::assertSame('modul_b', $this->sets->assignment($key, $set)?->optionKey);
    }

    public function testAnOptionOfAnotherSetIsRefused(): void
    {
        $set = $this->newSet();
        $other = $this->newSet(['name' => 'Másik']);
        $this->newOption($other, 'Modul A');
        $key = new RegistrationKey($this->event, '00A0');
        $this->seedRegistrations($key);

        $this->expectException(InvalidInput::class);
        $this->place($key, $set, 'modul_a');
    }

    public function testASetOfAnotherEventIsNotFound(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A');
        $key = new RegistrationKey($this->event + 1, '00A0');
        $this->seedRegistrations($key);

        $this->expectException(NotFound::class);
        $this->place($key, $set, 'modul_a');
    }

    public function testAMissingRegistrationIsNotFound(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A');

        $this->expectException(NotFound::class);
        $this->place(new RegistrationKey($this->event, '00A0'), $set, 'modul_a');
    }

    public function testTheEditorReadsTheEventsSetsWithFullAccessOnly(): void
    {
        $set = $this->newSet();
        $this->newOption($set, 'Modul A');
        $this->sets->tieRequestField($set, 'modulok', new CliActor(null));
        $GLOBALS['wp_rest_server'] = null;
        $request = new \WP_REST_Request('GET', '/kalaka-admin/v1/events/' . $this->event . '/assignment-sets');

        wp_set_current_user(self::factory()->user->create());
        self::assertSame(403, rest_do_request($request)->get_status());

        wp_set_current_user($this->fullAccessUser());
        $response = rest_do_request($request);
        self::assertSame(200, $response->get_status());
        self::assertSame([[
            'id' => $set,
            'name' => 'Modulok',
            'request_field_key' => 'modulok',
            'options' => [['value' => 'modul_a', 'label' => 'Modul A']],
        ]], $response->get_data());
        wp_set_current_user(0);
        $GLOBALS['wp_rest_server'] = null;
    }

    private function place(RegistrationKey $key, int $set, string $option): void
    {
        $this->runner->run(new PlaceRegistration(), [
            'event_id' => $key->eventId,
            'code' => $key->code,
            'set_id' => $set,
            'option' => $option,
        ], new CliActor(null));
    }
}
