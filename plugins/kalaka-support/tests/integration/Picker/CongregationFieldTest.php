<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Support\Picker;

use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Forms\Api\MoneyRelevantFieldType;
use Kalaka\Forms\Api\PathFieldType;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\Engine\FieldMarkup as EngineFieldMarkup;
use Kalaka\Forms\Module as FormsModule;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Mail\Body\BodyCheck;
use Kalaka\Support\Directory\SaveDirectoryNode;
use Kalaka\Support\Picker\CongregationFieldType;
use Kalaka\Tests\Integration\Forms\FormsTestCase;

/**
 * The congregation field in a form: contributed and registered, an answer checked against the live
 * directory at submit (only a live terminal leaf, owner ruling S28-1), stored as the node id with a snapshot
 * of its path (P1), read back as the path's external IDs and the leaf's live name (P2), compared by
 * `contains` and never `equals`, in a form and in a mail, opened with its pick (prefill), rendered with
 * core's field markup, and declared as the event's participant field like any field. The directory is
 * synthetic: a church with a district, a deanery and two congregations, and a second body with no levels.
 */
final class CongregationFieldTest extends FormsTestCase
{
    private const EVENT = 7;

    private const REQUIRED = ['name' => 'Teszt Anna', 'email' => 'teszt.anna@example.org'];

    /** @var array<string, int> the node ids by external ID */
    private array $ids = [];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();
        ObjectCache::clear();

        // The harness unregisters all meta keys after each test, so register them again the way `init` does.
        (new FormsModule())->registerMeta();
        wp_set_current_user($this->fullAccessUser());

        $this->node('ROOT', null, 'Egyház', 'Teszt Egyház');
        $this->node('D1', 'ROOT', 'Egyházkerület', 'Északi Teszt Egyházkerület');
        $this->node('D1-A', 'D1', 'Egyházmegye', 'Budai Teszt Egyházmegye');
        $this->node('D1-A-1', 'D1-A', 'Gyülekezet', 'Óbudai Teszt Gyülekezet');
        $this->node('D1-A-2', 'D1-A', 'Gyülekezet', 'Ottó Teszt');
        $this->node('D1-A-X', 'D1-A', 'Egyéb', 'Egyéb');
        $this->node('OTHER', null, 'Közösség', 'Másik Közösség');
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress names this hook in snake case
    public function tear_down(): void
    {
        ObjectCache::clear();
        parent::tear_down();
    }

    private function node(string $externalId, ?string $parent, string $type, string $name, bool $active = true): void
    {
        $result = $this->runner->run(new SaveDirectoryNode(), [
            'external_id' => $externalId,
            'parent_external_id' => $parent,
            'type' => $type,
            'name' => $name,
            'aliases' => [],
            'active' => $active,
        ], new UserActor($this->fullAccessUser()));
        self::assertIsArray($result);
        $this->ids[$externalId] = (int) $result['id'];
    }

    /** @param array<string, mixed> $attributes */
    private function congregation(array $attributes = []): string
    {
        return $this->block(CongregationFieldType::BLOCK_NAME, $attributes + [
            'key' => 'gyulekezet',
            'label' => 'Gyülekezet',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function definition(array $attributes = []): FormDefinition
    {
        return $this->forms->definition($this->readForm($this->slotForm($this->congregation($attributes))));
    }

    /** @return array<string, string> the field errors of a submit that must be refused */
    private function refused(FormDefinition $definition, mixed $answer): array
    {
        try {
            $this->forms->validate($definition, self::REQUIRED + ['gyulekezet' => $answer]);
        } catch (InvalidAnswers $e) {
            return $e->fields;
        }
        self::fail('The answer was accepted.');
    }

    private function stored(FormDefinition $definition, string $answer): ?string
    {
        return $this->forms->validate($definition, self::REQUIRED + ['gyulekezet' => $answer])->stored()['gyulekezet']
            ?? null;
    }

    /** What an answer stores: the leaf's id and the path's external IDs and types, root first. */
    private function snapshot(string $leaf): string
    {
        $types = [
            'ROOT' => 'Egyház',
            'D1' => 'Egyházkerület',
            'D1-A' => 'Egyházmegye',
            'D1-A-1' => 'Gyülekezet',
            'D1-A-2' => 'Gyülekezet',
            'D1-A-X' => 'Egyéb',
            'OTHER' => 'Közösség',
        ];
        $path = [];
        $parents = $leaf === 'OTHER' ? [] : ['ROOT', 'D1', 'D1-A'];
        foreach ([...$parents, $leaf] as $ext) {
            $path[] = ['ext' => $ext, 'type' => $types[$ext]];
        }

        return (string) wp_json_encode(
            ['node' => $this->ids[$leaf], 'path' => $path],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * A slot form with the congregation field and a branch on the condition, read back as its definition.
     *
     * @param array<string, mixed> $condition
     */
    private function branching(array $condition): FormDefinition
    {
        return $this->forms->definition($this->readForm($this->slotForm(
            $this->congregation()
            . '<!-- wp:kalaka/if ' . wp_json_encode(['condition' => $condition]) . ' -->'
            . $this->field('text', 'kerdes')
            . '<!-- /wp:kalaka/if -->'
        )));
    }

    /** @return array<string, mixed> "<key> <operator> <value>" as a condition */
    private static function compare(string $operator, string $value): array
    {
        return ['match' => 'all', 'items' => [['key' => 'gyulekezet', 'operator' => $operator, 'value' => $value]]];
    }

    private function type(): CongregationFieldType
    {
        $type = Contributions::current()->fieldTypes()[CongregationFieldType::BLOCK_NAME] ?? null;
        self::assertInstanceOf(CongregationFieldType::class, $type);

        return $type;
    }

    public function testTheTypeIsContributedMoneyRelevantAndItsBlockRegistered(): void
    {
        self::assertInstanceOf(MoneyRelevantFieldType::class, $this->type());
        self::assertInstanceOf(PathFieldType::class, $this->type());
        self::assertSame(ValueType::Choice, $this->type()->valueType());
        $block = \WP_Block_Type_Registry::get_instance()->get_registered(CongregationFieldType::BLOCK_NAME);
        self::assertInstanceOf(\WP_Block_Type::class, $block);
        self::assertSame('kalaka', $block->category);
        self::assertSame('Gyülekezet', $block->title);
        foreach (['key', 'label', 'required'] as $attribute) {
            self::assertArrayHasKey($attribute, $block->attributes);
        }
    }

    public function testALiveTerminalLeafIsStoredAsItsIdWithASnapshotOfItsPath(): void
    {
        $definition = $this->definition();

        self::assertSame($this->snapshot('D1-A-1'), $this->stored($definition, (string) $this->ids['D1-A-1']));
        self::assertSame(
            '{"node":' . $this->ids['D1-A-1'] . ',"path":[{"ext":"ROOT","type":"Egyház"},'
            . '{"ext":"D1","type":"Egyházkerület"},{"ext":"D1-A","type":"Egyházmegye"},'
            . '{"ext":"D1-A-1","type":"Gyülekezet"}]}',
            $this->stored($definition, (string) $this->ids['D1-A-1'])
        );

        // an "egyéb" leaf added by hand is an ordinary leaf
        self::assertSame($this->snapshot('D1-A-X'), $this->stored($definition, (string) $this->ids['D1-A-X']));

        // a body with no levels is a leaf at the root: its path is itself
        self::assertSame($this->snapshot('OTHER'), $this->stored($definition, (string) $this->ids['OTHER']));

        // spaces around it and leading zeros do not matter
        self::assertSame($this->snapshot('D1-A-2'), $this->stored($definition, ' 00' . $this->ids['D1-A-2'] . ' '));
    }

    public function testALevelIsNoAnswer(): void
    {
        $definition = $this->definition();

        foreach (['ROOT', 'D1', 'D1-A'] as $level) {
            self::assertSame(
                ['gyulekezet' => CongregationFieldType::NOT_LISTED],
                $this->refused($definition, (string) $this->ids[$level]),
                $level
            );
        }
    }

    public function testADeadNodeIsNoNewAnswer(): void
    {
        $definition = $this->definition();
        // deactivated itself
        $this->node('D1-A-2', 'D1-A', 'Gyülekezet', 'Ottó Teszt', false);
        self::assertSame(
            ['gyulekezet' => CongregationFieldType::NOT_LISTED],
            $this->refused($definition, (string) $this->ids['D1-A-2'])
        );

        // active, under a deactivated ancestor
        $this->node('D1', 'ROOT', 'Egyházkerület', 'Északi Teszt Egyházkerület', false);
        self::assertSame(
            ['gyulekezet' => CongregationFieldType::NOT_LISTED],
            $this->refused($definition, (string) $this->ids['D1-A-1'])
        );
    }

    public function testALeafThatGainsChildrenIsNoNewAnswer(): void
    {
        $this->node('D1-A-1-a', 'D1-A-1', 'Szórvány', 'Óbudai Teszt Szórvány', false);

        self::assertSame(
            ['gyulekezet' => CongregationFieldType::NOT_LISTED],
            $this->refused($this->definition(), (string) $this->ids['D1-A-1'])
        );
    }

    public function testAnythingElseIsNoAnswerAndANonStringIsInvalidInput(): void
    {
        $definition = $this->definition();

        foreach (['0', '999999', 'abc', '12a', '-5', '1.0', '1234567890123456789'] as $sent) {
            self::assertSame(
                ['gyulekezet' => CongregationFieldType::NOT_LISTED],
                $this->refused($definition, $sent),
                $sent
            );
        }

        $this->expectException(InvalidInput::class);
        $this->forms->validate($definition, self::REQUIRED + ['gyulekezet' => $this->ids['D1-A-1']]);
    }

    public function testARequiredFieldLeftEmptyHasCoresRequiredErrorAndAnOptionalOneStoresNothing(): void
    {
        self::assertSame(
            ['gyulekezet' => 'Ezt a mezőt ki kell töltened.'],
            $this->refused($this->definition(['required' => true]), '')
        );

        $validated = $this->forms->validate($this->definition(), self::REQUIRED + ['gyulekezet' => '']);
        self::assertArrayNotHasKey('gyulekezet', $validated->stored());
    }

    public function testTheValueIsThePathsExternalIdsAndTheLeafsLiveName(): void
    {
        $stored = $this->snapshot('D1-A-1');
        $value = $this->type()->value(new FieldTypeInput($stored, []));
        self::assertSame(ValueType::Choice, $value->type);
        self::assertSame(['ROOT', 'D1', 'D1-A', 'D1-A-1'], $value->data);
        self::assertSame('Óbudai Teszt Gyülekezet', $value->text);

        // a re-import moves the leaf, renames it and deactivates it: the path stays as stored, the name is live
        $this->node('D2', 'ROOT', 'Egyházkerület', 'Déli Teszt Egyházkerület');
        $this->node('D1-A-1', 'D2', 'Gyülekezet', 'Óbudai Új Teszt Gyülekezet', false);
        $value = $this->type()->value(new FieldTypeInput($stored, []));
        self::assertSame(['ROOT', 'D1', 'D1-A', 'D1-A-1'], $value->data);
        self::assertSame('Óbudai Új Teszt Gyülekezet', $value->text);

        // nothing chosen for an id with no node, nor for what is no stored answer
        foreach (['999999', '{"node":999999,"path":[]}', '{"node":"x"}', 'abc', ''] as $stored) {
            self::assertTrue($this->type()->value(new FieldTypeInput($stored, []))->isEmpty(), $stored);
        }
    }

    public function testAnAnswerStoredAsABareIdReadsItsPathFromTheDirectory(): void
    {
        $value = $this->type()->value(new FieldTypeInput((string) $this->ids['D1-A-2'], []));

        self::assertSame(['ROOT', 'D1', 'D1-A', 'D1-A-2'], $value->data);
        self::assertSame('Ottó Teszt', $value->text);
    }

    public function testAStoredAnswerOpensAsItsLeafsId(): void
    {
        $id = (string) $this->ids['D1-A-1'];
        self::assertSame($id, $this->type()->prefill(new FieldTypeInput($this->snapshot('D1-A-1'), [])));
        self::assertSame($id, $this->type()->prefill(new FieldTypeInput($id, [])));
        foreach (['', 'abc', '0', '{"node":0,"path":[]}'] as $stored) {
            self::assertNull($this->type()->prefill(new FieldTypeInput($stored, [])), $stored);
        }
    }

    public function testAConditionAsksWhetherTheLevelIsOneNode(): void
    {
        $facts = new ValueContext([
            EventValues::NAME => Value::text('Teszt tábor', 'Teszt tábor'),
            EventValues::REGISTRATION_OPENS => Value::none(ValueType::Date),
            EventValues::REGISTRATION_CLOSES => Value::none(ValueType::Date),
            EventValues::START => Value::none(ValueType::Date),
            EventValues::END => Value::none(ValueType::Date),
        ]);
        $shows = fn (FormDefinition $definition, string $leaf): bool => $this->forms->availability(
            $definition,
            self::REQUIRED + ['gyulekezet' => (string) $this->ids[$leaf]],
            $facts
        )->shows('kerdes');

        // "egyházmegye is D1-A": both its congregations, nothing else
        $deanery = $this->branching(self::compare('contains', 'D1-A'));
        self::assertTrue($shows($deanery, 'D1-A-1'));
        self::assertTrue($shows($deanery, 'D1-A-2'));
        self::assertFalse($shows($deanery, 'OTHER'));

        // "gyülekezet is D1-A-1": the leaf alone; an external ID is never matched inside another
        $leaf = $this->branching(self::compare('contains', 'D1-A-1'));
        self::assertTrue($shows($leaf, 'D1-A-1'));
        self::assertFalse($shows($leaf, 'D1-A-2'));
        // a node's ID is never matched inside another's: a prefix of every path names no node
        self::assertFalse($shows($this->branching(self::compare('contains', 'D1-')), 'D1-A-1'));

        // a deactivated node can still be named, so an old rule stays valid
        $this->node('D1', 'ROOT', 'Egyházkerület', 'Északi Teszt Egyházkerület', false);
        self::assertInstanceOf(FormDefinition::class, $this->branching(self::compare('contains', 'D1')));
    }

    public function testAConditionNeverComparesThePathWithEquals(): void
    {
        try {
            $this->branching(self::compare('equals', 'D1-A-1'));
            self::fail('A condition with equals was accepted.');
        } catch (SystemFailure $e) {
            self::assertStringContainsString('invalid_condition gyulekezet', $e->getMessage());
        }
    }

    public function testAMailConditionNeverComparesThePathWithEquals(): void
    {
        $this->insertChecked(
            $this->congregation(),
            ['kalaka_event_id' => self::EVENT, 'kalaka_placement' => 'additional']
        );
        $body = static fn (string $operator): string => '<!-- wp:kalaka/if '
            . wp_json_encode(['condition' => self::compare($operator, 'D1-A')])
            . ' --><!-- wp:paragraph --><p>Teszt</p><!-- /wp:paragraph --><!-- /wp:kalaka/if -->';

        self::assertNull(BodyCheck::problem($body('contains'), self::EVENT));
        self::assertSame('invalid_condition gyulekezet', BodyCheck::problem($body('equals'), self::EVENT));
    }

    /** @param array<string, mixed> $attributes */
    private function rendered(array $attributes = [], ?string $prefilled = null): string
    {
        $render = static fn (): string => render_block([
            'blockName' => CongregationFieldType::BLOCK_NAME,
            'attrs' => $attributes + ['key' => 'gyulekezet', 'label' => 'Gyülekezet', 'required' => true],
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => [],
        ]);

        return $prefilled === null ? $render() : EngineFieldMarkup::withPrefill(['gyulekezet' => $prefilled], $render);
    }

    public function testItRendersAComboboxWithoutANameAndTheAnswerAsAHiddenInput(): void
    {
        $html = $this->rendered();

        self::assertStringContainsString('data-kalaka-field="gyulekezet"', $html);
        self::assertStringContainsString('data-kalaka-congregation', $html);
        self::assertStringContainsString(
            'data-kalaka-nodes="' . esc_url(rest_url('kalaka-support/v1/nodes')) . '"',
            $html
        );
        self::assertStringNotContainsString('data-kalaka-current', $html);
        self::assertMatchesRegularExpression(
            '/<label for="[^"]+">Gyülekezet<span class="kalaka-field__required"/',
            $html
        );
        self::assertMatchesRegularExpression(
            '/<input type="text" id="kalaka-field-gyulekezet" role="combobox" autocomplete="off"'
            . ' aria-autocomplete="list" aria-expanded="false" aria-controls="kalaka-field-gyulekezet-options"'
            . ' placeholder="Keress, vagy válassz a listából" value=""[^>]* aria-required="true" data-kalaka-search>/',
            $html
        );
        self::assertSame(1, substr_count($html, ' name="'));
        self::assertMatchesRegularExpression(
            '/<input type="hidden" name="gyulekezet" autocomplete="off"\s+value="">/',
            $html
        );
        self::assertStringContainsString('class="kalaka-field__error" hidden', $html);
        // a required field has no ×
        self::assertStringNotContainsString('data-kalaka-remove', $html);
        // the field comes before the hidden input: a field error focuses the field
        self::assertLessThan(strpos($html, 'type="hidden"'), strpos($html, 'role="combobox"'));
        // the dropdown's message line is at its top, before the options
        self::assertLessThan(strpos($html, 'role="listbox"'), strpos($html, 'data-kalaka-message'));
        // the picker's own stylesheet
        $block = \WP_Block_Type_Registry::get_instance()->get_registered(CongregationFieldType::BLOCK_NAME);
        self::assertInstanceOf(\WP_Block_Type::class, $block);
        self::assertNotEmpty($block->style_handles);

        // an optional field has the ×, hidden while there is no pick
        self::assertMatchesRegularExpression(
            '/<button type="button" aria-label="Választás törlése – Gyülekezet" data-kalaka-remove\s+hidden>/',
            $this->rendered(['required' => false])
        );
    }

    public function testItOpensWithItsPickShownItsPathDescribingIt(): void
    {
        $html = $this->rendered(['required' => false], (string) $this->ids['D1-A-1']);

        self::assertStringContainsString('value="Óbudai Teszt Gyülekezet"', $html);
        self::assertMatchesRegularExpression(
            '/<input type="hidden" name="gyulekezet" autocomplete="off"\s+value="' . $this->ids['D1-A-1'] . '">/',
            $html
        );
        self::assertStringContainsString('aria-describedby="kalaka-field-gyulekezet-path"', $html);
        self::assertMatchesRegularExpression(
            '/id="kalaka-field-gyulekezet-path"[^>]*\s*>'
            . 'Teszt Egyház, Északi Teszt Egyházkerület, Budai Teszt Egyházmegye</',
            $html
        );
        // never an external ID
        self::assertStringNotContainsString('D1-A', $html);
        self::assertStringContainsString('&quot;live&quot;:true', $html);
        // H7's line stays hidden for a live pick, and the × shows
        self::assertMatchesRegularExpression('/data-kalaka-stale\s+hidden>/', $html);
        self::assertMatchesRegularExpression('/data-kalaka-remove\s+>/', $html);
    }

    public function testAPickThatIsNoLongerAnAnswerOpensWithItsLine(): void
    {
        $this->node('D1-A-2', 'D1-A', 'Gyülekezet', 'Ottó Teszt', false);
        $html = $this->rendered([], (string) $this->ids['D1-A-2']);

        self::assertStringContainsString('value="Ottó Teszt"', $html);
        self::assertStringContainsString(
            'aria-describedby="kalaka-field-gyulekezet-path kalaka-field-gyulekezet-stale"',
            $html
        );
        self::assertMatchesRegularExpression(
            '/data-kalaka-stale\s*>A választott elem már nem választható\. Válassz a listából\.</',
            $html
        );
        self::assertStringContainsString('&quot;live&quot;:false', $html);
    }

    public function testItIsDeclaredAsTheEventsParticipantFieldWithoutOptions(): void
    {
        $this->insertChecked(
            $this->congregation(),
            ['kalaka_event_id' => self::EVENT, 'kalaka_placement' => 'additional']
        );

        $fields = [];
        foreach ($this->forms->participantFields(self::EVENT) as $field) {
            $fields[$field->key] = $field;
        }
        self::assertArrayHasKey('gyulekezet', $fields);
        self::assertSame(CongregationFieldType::BLOCK_NAME, $fields['gyulekezet']->type);
        self::assertSame('Gyülekezet', $fields['gyulekezet']->title);
        self::assertSame('form', $fields['gyulekezet']->source);
        self::assertSame([], $fields['gyulekezet']->options);
        self::assertSame('Gyülekezet', $fields['gyulekezet']->typeLabel);
    }
}
