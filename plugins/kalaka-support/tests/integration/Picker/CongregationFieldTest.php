<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Support\Picker;

use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Forms\Api\MoneyRelevantFieldType;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\Module as FormsModule;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Support\Directory\SaveDirectoryNode;
use Kalaka\Support\Picker\CongregationFieldType;
use Kalaka\Tests\Integration\Forms\FormsTestCase;

/**
 * The congregation field in a form: contributed and registered, an answer checked against the live
 * directory at submit (only a live terminal leaf, owner ruling S28-1), stored as the node id, read back as
 * the node's external ID and name, rendered with core's field markup, and declared as the event's
 * participant field like any field. The directory is synthetic: a church with a district, a deanery and two
 * congregations, and a second body with no levels.
 */
final class CongregationFieldTest extends FormsTestCase
{
    private const EVENT = 7;

    private const REQUIRED = ['name' => 'Teszt Anna', 'email' => 'teszt.anna@example.org'];

    /** @var array<string, int> the node ids by external ID */
    private array $ids = [];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();
        ObjectCache::clear();

        // The harness unregisters all meta keys after each test, so register them again the way `init` does.
        (new FormsModule())->registerMeta();
        wp_set_current_user($this->fullAccessUser());

        $this->node('ROOT', null, 'Egyház', 'Teszt Egyház');
        $this->node('D1', 'ROOT', 'Egyházkerület', 'Teszt Egyházkerület 1');
        $this->node('D1-A', 'D1', 'Egyházmegye', 'Teszt Egyházmegye 1');
        $this->node('D1-A-1', 'D1-A', 'Gyülekezet', 'Teszt gyülekezet A');
        $this->node('D1-A-2', 'D1-A', 'Gyülekezet', 'Teszt gyülekezet B');
        $this->node('D1-A-X', 'D1-A', 'Egyéb', 'Egyéb');
        $this->node('OTHER', null, 'Közösség', 'Másik Teszt Közösség');
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
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

    private function type(): CongregationFieldType
    {
        $type = Contributions::current()->fieldTypes()[CongregationFieldType::BLOCK_NAME] ?? null;
        self::assertInstanceOf(CongregationFieldType::class, $type);

        return $type;
    }

    public function testTheTypeIsContributedMoneyRelevantAndItsBlockRegistered(): void
    {
        self::assertInstanceOf(MoneyRelevantFieldType::class, $this->type());
        self::assertSame(ValueType::Choice, $this->type()->valueType());
        $block = \WP_Block_Type_Registry::get_instance()->get_registered(CongregationFieldType::BLOCK_NAME);
        self::assertInstanceOf(\WP_Block_Type::class, $block);
        self::assertSame('kalaka', $block->category);
        self::assertSame('Gyülekezet', $block->title);
        foreach (['key', 'label', 'required'] as $attribute) {
            self::assertArrayHasKey($attribute, $block->attributes);
        }
    }

    public function testALiveTerminalLeafIsStoredAsItsNodeIdAlone(): void
    {
        $definition = $this->definition();

        self::assertSame((string) $this->ids['D1-A-1'], $this->stored($definition, (string) $this->ids['D1-A-1']));

        // an "egyéb" leaf added by hand is an ordinary leaf
        self::assertSame((string) $this->ids['D1-A-X'], $this->stored($definition, (string) $this->ids['D1-A-X']));

        // a body with no levels is a leaf at the root
        self::assertSame((string) $this->ids['OTHER'], $this->stored($definition, (string) $this->ids['OTHER']));

        // spaces around it and leading zeros are not stored
        self::assertSame((string) $this->ids['D1-A-2'], $this->stored($definition, ' 00' . $this->ids['D1-A-2'] . ' '));
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
        $this->node('D1-A-2', 'D1-A', 'Gyülekezet', 'Teszt gyülekezet B', false);
        self::assertSame(
            ['gyulekezet' => CongregationFieldType::NOT_LISTED],
            $this->refused($definition, (string) $this->ids['D1-A-2'])
        );

        // active, under a deactivated ancestor
        $this->node('D1', 'ROOT', 'Egyházkerület', 'Teszt Egyházkerület 1', false);
        self::assertSame(
            ['gyulekezet' => CongregationFieldType::NOT_LISTED],
            $this->refused($definition, (string) $this->ids['D1-A-1'])
        );
    }

    public function testALeafThatGainsChildrenIsNoNewAnswer(): void
    {
        $this->node('D1-A-1-a', 'D1-A-1', 'Szórvány', 'Teszt szórvány', false);

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

    public function testTheValueIsTheNodesExternalIdAndNameLiveOrNot(): void
    {
        $value = $this->type()->value(new FieldTypeInput((string) $this->ids['D1-A-1'], []));
        self::assertSame(ValueType::Choice, $value->type);
        self::assertSame(['D1-A-1'], $value->data);
        self::assertSame('Teszt gyülekezet A', $value->text);

        // a node is never deleted: a stored id still reads after the node is deactivated
        $this->node('D1-A-1', 'D1-A', 'Gyülekezet', 'Teszt gyülekezet A', false);
        $value = $this->type()->value(new FieldTypeInput((string) $this->ids['D1-A-1'], []));
        self::assertSame(['D1-A-1'], $value->data);
        self::assertSame('Teszt gyülekezet A', $value->text);

        // nothing chosen for an id with no node
        $value = $this->type()->value(new FieldTypeInput('999999', []));
        self::assertTrue($value->isEmpty());
    }

    public function testAConditionComparesTheNodesExternalId(): void
    {
        $condition = [
            'match' => 'all',
            'items' => [['key' => 'gyulekezet', 'operator' => 'equals', 'value' => 'D1-A-1']],
        ];
        $definition = $this->forms->definition($this->readForm($this->slotForm(
            $this->congregation()
            . '<!-- wp:kalaka/if ' . wp_json_encode(['condition' => $condition]) . ' -->'
            . $this->field('text', 'kerdes')
            . '<!-- /wp:kalaka/if -->'
        )));
        $facts = new ValueContext([
            EventValues::NAME => Value::text('Teszt tábor', 'Teszt tábor'),
            EventValues::REGISTRATION_OPENS => Value::none(ValueType::Date),
            EventValues::REGISTRATION_CLOSES => Value::none(ValueType::Date),
            EventValues::START => Value::none(ValueType::Date),
            EventValues::END => Value::none(ValueType::Date),
        ]);

        $picked = $this->forms->availability(
            $definition,
            self::REQUIRED + ['gyulekezet' => (string) $this->ids['D1-A-1']],
            $facts
        );
        self::assertTrue($picked->shows('kerdes'));

        $other = $this->forms->availability(
            $definition,
            self::REQUIRED + ['gyulekezet' => (string) $this->ids['D1-A-2']],
            $facts
        );
        self::assertFalse($other->shows('kerdes'));
    }

    public function testItRendersASearchBoxWithoutANameAndTheAnswerAsAHiddenInput(): void
    {
        $html = render_block([
            'blockName' => CongregationFieldType::BLOCK_NAME,
            'attrs' => ['key' => 'gyulekezet', 'label' => 'Gyülekezet', 'required' => true],
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => [],
        ]);

        self::assertStringContainsString('data-kalaka-field="gyulekezet"', $html);
        self::assertStringContainsString('data-kalaka-congregation', $html);
        self::assertStringContainsString(
            'data-kalaka-nodes="' . esc_url(rest_url('kalaka-support/v1/nodes')) . '"',
            $html
        );
        self::assertMatchesRegularExpression(
            '/<label for="[^"]+">Gyülekezet<span class="kalaka-field__required"/',
            $html
        );
        self::assertMatchesRegularExpression(
            '/<input type="search" id="[^"]+" autocomplete="off"[^>]* aria-required="true" data-kalaka-search>/',
            $html
        );
        self::assertSame(1, substr_count($html, ' name="'));
        self::assertStringContainsString('<input type="hidden" name="gyulekezet" value="">', $html);
        self::assertStringContainsString('<button type="button" data-kalaka-up>Egy szinttel feljebb</button>', $html);
        self::assertStringContainsString('class="kalaka-field__error" hidden', $html);
        // the search box comes before the hidden input: a field error focuses the search box
        self::assertLessThan(strpos($html, 'type="hidden"'), strpos($html, 'type="search"'));
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
