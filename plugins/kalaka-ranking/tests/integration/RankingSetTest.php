<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ranking;

use Kalaka\Assignments\Api\AssignmentSets;
use Kalaka\Events\Api\Events;
use Kalaka\Events\PublishEvent;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\DefinitionCheck;
use Kalaka\Forms\EventFormHost;
use Kalaka\Forms\Engine\Rest\FormRoutes;
use Kalaka\Forms\Module as FormsModule;
use Kalaka\Forms\ParticipantField\SharedKeyCheck;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\GuestActor;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Portal\ActiveRegistrationState;
use Kalaka\Ranking\RankingFieldType;
use Kalaka\Registrations\Api\RegistrationSubmit;
use Kalaka\Registrations\Api\SubmitRequest;
use Kalaka\Registrations\Api\SubmitToken;
use Kalaka\Tests\Integration\Forms\FormsTestCase;

/**
 * S44: the ranking names its assignment set (S44-1), its options are the set's, offered by their
 * condition on the track answer before it, held by the ranking block itself (`optionConditions`, S76), the
 * field it declares is the set's one request field, and outside the set's choice window it does not show and
 * writes nothing (C3). The set and its options are IVK's shape with invented modules: ifivezető modules A–C
 * for everybody, "Iskolamisszió" and "PRO" only for their track.
 */
final class RankingSetTest extends FormsTestCase
{
    private const NAMES = ['name' => 'Teszt Anna', 'email' => 'teszt.anna@example.org'];

    private AssignmentSets $sets;

    private int $event;

    private int $set;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        // The harness unregisters all meta keys after each test, so register them again the way `init` does.
        (new FormsModule())->registerMeta();
        wp_set_current_user($this->fullAccessUser());
        $GLOBALS['wp_rest_server'] = null;
        $this->sets = new AssignmentSets($this->runner);
        $this->event = $this->create();
        $this->update($this->event, ['capacity' => 10]);
        $this->transition(new PublishEvent(), $this->event);
        // The track is declared by a form first: a condition reads only the event's participant fields.
        $this->insertChecked($this->slotFields() . $this->track(), $this->meta(), ['post_status' => 'draft']);
        $this->set = $this->newSet('2026-11-01 08:00', '2027-02-15 20:00');
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress names this hook in snake case
    public function tear_down(): void
    {
        $GLOBALS['wp_rest_server'] = null;
        wp_set_current_user(0);

        parent::tear_down();
    }

    /** @return array<string, mixed> */
    private function meta(string $placement = 'registration'): array
    {
        return ['kalaka_event_id' => $this->event, 'kalaka_placement' => $placement];
    }

    private function track(): string
    {
        return $this->field('choice', 'szolgalati_terulet', ['options' => [
            ['value' => 'ifivezeto', 'label' => 'Ifivezető'],
            ['value' => 'iskolamisszio', 'label' => 'Iskolamisszió'],
            ['value' => 'pro', 'label' => 'PRO'],
        ]]);
    }

    private function newSet(?string $opens, ?string $closes): int
    {
        $set = $this->sets->save([
            'event_id' => $this->event,
            'name' => 'Modulok',
            'required' => true,
            'window_opens_at' => $opens,
            'window_closes_at' => $closes,
            'published' => false,
        ], new CliActor(null));
        foreach (['Modul A', 'Modul B', 'Modul C'] as $position => $label) {
            $this->option($set, $label, $position);
        }
        $this->option($set, 'Iskolamisszió', 7);
        $this->option($set, 'PRO', 8);

        return $set;
    }

    private function option(int $set, string $label, int $position): void
    {
        $this->sets->saveOption([
            'set_id' => $set,
            'label' => $label,
            'capacity' => 30,
            'leaders' => '',
            'place' => '',
            'position' => $position,
        ], new CliActor(null));
    }

    /**
     * The ranking block's conditions: "Iskolamisszió" and "PRO" only for their track.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function trackConditions(): array
    {
        $on = static fn (string $track): array => ['match' => 'all', 'items' => [
            ['key' => 'szolgalati_terulet', 'operator' => 'equals', 'value' => $track],
        ]];

        return ['iskolamisszio' => $on('iskolamisszio'), 'pro' => $on('pro')];
    }

    /** @param array<string, mixed> $attributes */
    private function ranking(string $key = 'modulok', ?int $set = null, array $attributes = []): string
    {
        return $this->block(RankingFieldType::BLOCK_NAME, $attributes + [
            'key' => $key,
            'label' => 'Modulok',
            'set' => $set ?? $this->set,
            'optionConditions' => self::trackConditions(),
            'min' => 1,
            'max' => 3,
        ]);
    }

    private function form(string $ranking = ''): int
    {
        $content = $this->slotFields() . $this->track() . ($ranking === '' ? $this->ranking() : $ranking);

        return $this->insertChecked($content, $this->meta());
    }

    private function facts(): ValueContext
    {
        $event = (new Events())->find($this->event);
        self::assertNotNull($event);

        return new ValueContext(EventValues::of($event));
    }

    /** @return list<string>|null */
    private function offered(int $form, ?string $track): ?array
    {
        $definition = $this->forms->definition($this->readForm($form));
        $answers = $track === null ? [] : ['szolgalati_terulet' => $track];

        return $this->forms->availability($definition, $answers, $this->facts())->offeredOptions('modulok');
    }

    public function testTheSetAttributeDecidesTheMode(): void
    {
        $block = \WP_Block_Type_Registry::get_instance()->get_registered(RankingFieldType::BLOCK_NAME);
        self::assertInstanceOf(\WP_Block_Type::class, $block);
        self::assertSame('integer', $block->attributes['set']['type'] ?? null);
        self::assertArrayHasKey('options', $block->attributes);
        self::assertSame('object', $block->attributes['optionConditions']['type'] ?? null);
        self::assertSame(5, (new RankingFieldType())->requestSet(['set' => 5]));
        // 0 is a set, none chosen yet; no attribute (a block of the own-options days) is own options.
        self::assertSame(0, (new RankingFieldType())->requestSet(['set' => 0]));
        self::assertNull((new RankingFieldType())->requestSet(['set' => '5']));
        self::assertNull((new RankingFieldType())->requestSet(['set' => null]));
        self::assertNull((new RankingFieldType())->requestSet([]));
    }

    public function testEachTrackIsOfferedTheModulesOfEverybodyAndItsOwn(): void
    {
        // C1: the ifivezető modules for everybody; iskolamisszió and PRO only for their track; an
        // unanswered track reads as empty.
        $form = $this->form();
        self::assertSame(['modul_a', 'modul_b', 'modul_c'], $this->offered($form, 'ifivezeto'));
        self::assertSame(['modul_a', 'modul_b', 'modul_c', 'iskolamisszio'], $this->offered($form, 'iskolamisszio'));
        self::assertSame(['modul_a', 'modul_b', 'modul_c', 'pro'], $this->offered($form, 'pro'));
        self::assertSame(['modul_a', 'modul_b', 'modul_c'], $this->offered($form, null));
    }

    public function testASubmitStoresTheOfferedOptionsInRankOrderAndDropsAnOptionNotOffered(): void
    {
        $definition = $this->forms->definition($this->readForm($this->form()));

        // C2: PRO is not secret; a PRO person ranks the PRO module like any other.
        $pro = $this->forms->validate($definition, self::NAMES + [
            'szolgalati_terulet' => 'pro',
            'modulok' => ['pro', 'modul_b'],
        ], $this->facts());
        self::assertSame('["pro","modul_b"]', $pro->stored()['modulok']);

        $ifi = $this->forms->validate($definition, self::NAMES + [
            'szolgalati_terulet' => 'ifivezeto',
            'modulok' => ['pro', 'modul_b'],
        ], $this->facts());
        self::assertSame('["modul_b"]', $ifi->stored()['modulok']);
    }

    public function testTheValueNumbersTheSetsLabels(): void
    {
        $definition = $this->forms->definition($this->readForm($this->form()));
        $validated = $this->forms->validate($definition, self::NAMES + [
            'szolgalati_terulet' => 'iskolamisszio',
            'modulok' => ['iskolamisszio', 'modul_a'],
        ], $this->facts());

        $field = $definition->field('modulok');
        self::assertNotNull($field);
        $value = (new RankingFieldType())->value(
            new FieldTypeInput($validated->stored()['modulok'], $field->attributes)
        );
        self::assertSame('1. Iskolamisszió, 2. Modul A', $value->text);
    }

    public function testOutsideTheWindowTheRankingDoesNotShowAndWritesNothing(): void
    {
        // C3: the window closed before the clock's moment (2026-11-30 08:00 UTC); registration is still open.
        $closed = $this->newSet('2026-10-01 08:00', '2026-11-15 20:00');
        $form = $this->form($this->ranking('modulok_2', $closed, ['required' => true]));
        $definition = $this->forms->definition($this->readForm($form));

        $availability = $this->forms->availability($definition, [], $this->facts());
        self::assertFalse($availability->shows('modulok_2'));
        $validated = $this->forms->validate($definition, self::NAMES + [
            'szolgalati_terulet' => 'pro',
            'modulok_2' => ['modul_a'],
        ], $this->facts());
        self::assertArrayNotHasKey('modulok_2', $validated->stored());

        $html = do_blocks('<!-- wp:kalaka/form ' . wp_json_encode(['formId' => $form]) . ' /-->');
        self::assertStringNotContainsString('data-kalaka-ranking', $html);
        self::assertStringContainsString('data-kalaka-field="szolgalati_terulet"', $html);
    }

    public function testTheReFetchReRendersTheRankingWithItsOfferedOptions(): void
    {
        $form = $this->form();
        $request = new \WP_REST_Request('POST', '/' . FormRoutes::NAMESPACE . '/forms/' . $form . '/availability');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode([
            'version' => $this->readForm($form)->version,
            'answers' => ['szolgalati_terulet' => 'pro'],
        ]));
        $data = rest_do_request($request)->get_data();
        self::assertIsArray($data);

        self::assertSame(['modul_a', 'modul_b', 'modul_c', 'pro'], $data['availability']['options']['modulok']);
        $part = (string) ((array) $data['parts'])['modulok'];
        self::assertStringContainsString('data-kalaka-ranking', $part);
        self::assertStringContainsString('value="pro"', $part);
        self::assertStringNotContainsString('value="iskolamisszio"', $part);
    }

    public function testTheFirstSaveTiesTheSetToTheFieldTheRankingDeclares(): void
    {
        $this->form();

        self::assertSame('modulok', $this->sets->find($this->set)?->requestFieldKey);
    }

    public function testASecondDifferentFieldNamingTheSameSetIsRefusedOnSave(): void
    {
        $this->form();

        $content = $this->slotFields() . $this->track() . $this->ranking('masik_rangsor');
        self::assertSame(
            [SharedKeyCheck::REQUEST_FIELD_TAKEN, 'masik_rangsor'],
            DefinitionCheck::problem($content, 0, FormPlacement::Registration, $this->event)
        );
        // Within one form too.
        $fresh = $this->newSet(null, null);
        $twice = $this->slotFields() . $this->track()
            . $this->ranking('elso', $fresh) . $this->ranking('masodik', $fresh);
        self::assertSame(
            [SharedKeyCheck::REQUEST_FIELD_TAKEN, 'masodik'],
            DefinitionCheck::problem($twice, 0, FormPlacement::Registration, $this->event)
        );
    }

    public function testTheSameFieldInAnotherFormJoinsTheSetsRequestField(): void
    {
        // The re-ranking form (S60) holds the same key; here a second form that holds the track too.
        $this->form();

        $content = $this->slotFields() . $this->track() . $this->ranking();
        self::assertNull(DefinitionCheck::problem($content, 0, FormPlacement::Registration, $this->event));
    }

    public function testOneFieldIsNotTheRequestFieldOfTwoSets(): void
    {
        $this->form();
        $other = $this->newSet(null, null);

        $content = $this->slotFields() . $this->track() . $this->ranking('modulok', $other);
        self::assertSame(
            [SharedKeyCheck::REQUEST_FIELD_TAKEN, 'modulok'],
            DefinitionCheck::problem($content, 0, FormPlacement::Registration, $this->event)
        );
    }

    public function testARankingWithNoSetChosenOrAnotherEventsSetIsRefused(): void
    {
        $content = $this->slotFields() . $this->track() . $this->block(RankingFieldType::BLOCK_NAME, [
            'key' => 'modulok',
            'label' => 'Modulok',
            'set' => 0,
        ]);
        self::assertSame(
            [SharedKeyCheck::UNKNOWN_SET, 'modulok'],
            DefinitionCheck::problem($content, 0, FormPlacement::Registration, $this->event)
        );

        $elsewhere = $this->create('Másik tábor');
        self::assertSame(
            [SharedKeyCheck::UNKNOWN_SET, 'modulok'],
            DefinitionCheck::problem($this->slotFields() . $this->ranking(), 0, FormPlacement::Registration, $elsewhere)
        );
    }

    public function testAFormWrittenAroundTheCheckStaysClosed(): void
    {
        $this->form();
        $around = $this->insertForm(
            $this->slotFields() . $this->track() . $this->ranking('masik_rangsor'),
            $this->meta()
        );

        $this->expectException(SystemFailure::class);
        $this->expectExceptionMessage(SharedKeyCheck::REQUEST_FIELD_TAKEN . ' masik_rangsor');
        $this->forms->definition($this->readForm($around));
    }

    public function testAConditionOnAFieldThatIsNotBeforeTheRankingMakesTheFormUnusable(): void
    {
        // The block's conditions read the track: a form with the ranking but not the track before it cannot
        // offer the set's options.
        $content = $this->slotFields() . $this->ranking() . $this->track();
        self::assertSame(
            ['condition_key_not_earlier', 'szolgalati_terulet'],
            DefinitionCheck::problem($content, 0, FormPlacement::Registration, $this->event)
        );
    }

    public function testTheSaveRefusesAConditionForAKeyTheSetHasNoOptionFor(): void
    {
        $content = $this->slotFields() . $this->track()
            . $this->ranking('modulok', null, ['optionConditions' => self::trackConditions() + [
                'modul_x' => self::trackConditions()['pro'],
            ]]);

        self::assertSame(
            ['unknown_option', 'modulok'],
            DefinitionCheck::problem($content, 0, FormPlacement::Registration, $this->event)
        );
        // The same block with the keys of the set's options is fine.
        self::assertNull(DefinitionCheck::problem(
            $this->slotFields() . $this->track() . $this->ranking(),
            0,
            FormPlacement::Registration,
            $this->event
        ));
    }

    public function testAKeyWhoseOptionIsGoneIsIgnoredWhenTheFormIsRead(): void
    {
        $form = $this->form();
        // Staff remove "PRO": the form must not close over its condition.
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'kalaka_assignment_options', ['set_id' => $this->set, 'option_key' => 'pro']);

        $definition = $this->forms->definition($this->readForm($form));
        self::assertSame(['iskolamisszio'], array_keys($definition->field('modulok')?->optionConditions ?? []));
        self::assertSame(['modul_a', 'modul_b', 'modul_c'], $this->offered($form, 'pro'));
        self::assertSame(['modul_a', 'modul_b', 'modul_c', 'iskolamisszio'], $this->offered($form, 'iskolamisszio'));
    }

    public function testAnOptionConditionThatIsNotAnObjectIsRefused(): void
    {
        $content = $this->slotFields() . $this->track()
            . $this->ranking('modulok', null, ['optionConditions' => 'pro']);

        self::assertSame(
            ['invalid_option_conditions', 'modulok'],
            DefinitionCheck::problem($content, 0, FormPlacement::Registration, $this->event)
        );
    }

    public function testAReRankingFormsOptionConditionsReadTheStoredTrackItDoesNotHold(): void
    {
        $slot = $this->form();
        $registration = (new RegistrationSubmit($this->runner))->submit(
            new SubmitRequest(
                $slot,
                $this->readForm($slot)->version,
                self::NAMES + ['szolgalati_terulet' => 'iskolamisszio', 'modulok' => ['modul_a']],
                SubmitToken::generate()
            ),
            new GuestActor()
        )->registration;
        ActiveRegistrationState::set($registration, false);

        try {
            // The re-ranking form holds only the ranking: the track is read from the registration (CF2). The
            // same content as a registration form has no such track before the ranking, so the save refuses it.
            self::assertSame(
                ['condition_key_not_earlier', 'szolgalati_terulet'],
                DefinitionCheck::problem($this->ranking(), 0, FormPlacement::Registration, $this->event)
            );
            self::assertNull(DefinitionCheck::problem($this->ranking(), 0, FormPlacement::Additional, $this->event));
            $form = $this->insertChecked($this->ranking(), $this->meta('additional'));

            $definition = $this->forms->definition($this->readForm($form));
            $facts = (new EventFormHost())->facts($this->readForm($form));
            self::assertNotNull($facts);
            $availability = $this->forms->availability($definition, [], $facts);

            self::assertSame(
                ['modul_a', 'modul_b', 'modul_c', 'iskolamisszio'],
                $availability->offeredOptions('modulok')
            );
            // The submit's walk offers the same options, so "PRO" (not this track's) is dropped.
            $ranked = $this->forms->validate($definition, ['modulok' => ['iskolamisszio', 'pro']], $facts);
            self::assertSame('["iskolamisszio"]', $ranked->stored()['modulok']);
        } finally {
            ActiveRegistrationState::set(null, false);
        }
    }
}
