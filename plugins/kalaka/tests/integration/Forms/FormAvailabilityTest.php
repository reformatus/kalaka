<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\EventState;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Money\Currency;

/**
 * S76: one walk decides what a form shows, for a draft's answers (`Forms::availability()`) and at
 * submit (`Forms::validate()`): branches, fields, offered options, with event date references.
 */
final class FormAvailabilityTest extends FormsTestCase
{
    /** @param array<string, mixed> $condition */
    private function branch(string $name, ?array $condition, string $inner): string
    {
        return '<!-- wp:kalaka/' . $name . ($condition === null ? '' : ' '
            . wp_json_encode(['condition' => $condition]))
            . ' -->' . $inner . '<!-- /wp:kalaka/' . $name . ' -->';
    }

    /**
     * @param mixed $value a fixed value or a date reference
     * @return array<string, mixed>
     */
    private function when(string $key, string $operator, mixed $value = null): array
    {
        return [
            'match' => 'all',
            'items' => [['key' => $key, 'operator' => $operator] + ($value === null ? [] : ['value' => $value])],
        ];
    }

    private function definition(string $content): FormDefinition
    {
        return $this->forms->definition($this->readForm($this->slotForm($content)));
    }

    /** @return array<string, mixed> */
    private function slot(): array
    {
        return ['name' => 'Teszt Elek', 'email' => 'teszt@example.org'];
    }

    private function eventFacts(string $start = '2027-07-10'): ValueContext
    {
        return new ValueContext([
            EventValues::NAME => Value::text('Teszt tábor', 'Teszt tábor'),
            EventValues::REGISTRATION_OPENS => Value::none(ValueType::Date),
            EventValues::REGISTRATION_CLOSES => Value::none(ValueType::Date),
            EventValues::START => Value::date($start, $start),
            EventValues::END => Value::none(ValueType::Date),
        ]);
    }

    /** The track form (S44): the track picks the module options and the track's own questions. */
    private function trackForm(): FormDefinition
    {
        $tracks = [
            ['value' => 'ifivezeto', 'label' => 'Ifivezető'],
            ['value' => 'iskolamisszio', 'label' => 'Iskolamisszió'],
            ['value' => 'pro', 'label' => 'PRO'],
        ];
        $modules = [
            ['value' => 'ifi_a', 'label' => 'Ifi A'],
            ['value' => 'ifi_b', 'label' => 'Ifi B'],
            [
                'value' => 'iskola_a',
                'label' => 'Iskola A',
                'condition' => $this->when('track', 'equals', 'iskolamisszio'),
            ],
            ['value' => 'pro_a', 'label' => 'PRO A', 'condition' => $this->when('track', 'equals', 'pro')],
        ];

        return $this->definition(
            $this->field('choice', 'track', ['options' => $tracks, 'required' => true])
            . $this->branch(
                'if',
                $this->when('track', 'equals', 'pro'),
                $this->field('text', 'pro_question')
                . $this->branch('if', $this->when('pro_question', 'empty'), $this->field('text', 'pro_why'))
            )
            . $this->branch('else', null, $this->field('text', 'other_question'))
            . $this->field('choice', 'modules', ['options' => $modules, 'multiple' => true])
            . $this->field('choice', 'first', ['options' => $modules, 'required' => true])
        );
    }

    public function testChainsAreNumberedInDocumentOrderAndTriggersAreTheKeysConditionsRead(): void
    {
        $definition = $this->trackForm();

        self::assertCount(2, $definition->chains);
        self::assertSame([0, 1], array_map(static fn ($chain): int => $chain->number, $definition->chains));
        self::assertSame([], $definition->chains[0]->outer);
        self::assertSame(0, $definition->chains[1]->outer[0]->chain);
        self::assertSame(['track', 'pro_question'], $definition->triggers);
    }

    public function testADraftShowsTheHeldBranchesFieldsAndOfferedOptions(): void
    {
        $availability = $this->forms->availability(
            $this->trackForm(),
            $this->slot() + ['track' => 'pro'],
            $this->eventFacts()
        );

        self::assertSame([0, 0], $availability->chains);
        self::assertTrue($availability->shows('pro_question'));
        self::assertTrue($availability->shows('pro_why'));
        self::assertFalse($availability->shows('other_question'));
        self::assertSame(['ifi_a', 'ifi_b', 'pro_a'], $availability->offeredOptions('modules'));
        self::assertSame(['ifi_a', 'ifi_b', 'pro_a'], $availability->offeredOptions('first'));
        self::assertNull($availability->offeredOptions('track'));
        self::assertSame(
            [
                'fields' => [
                    'name' => true,
                    'email' => true,
                    'track' => true,
                    'pro_question' => true,
                    'pro_why' => true,
                    'other_question' => false,
                    'modules' => true,
                    'first' => true,
                ],
                'chains' => [0, 0],
                'options' => ['modules' => ['ifi_a', 'ifi_b', 'pro_a'], 'first' => ['ifi_a', 'ifi_b', 'pro_a']],
                'triggers' => ['track', 'pro_question'],
            ],
            $availability->toArray()
        );
    }

    public function testAChainInsideABranchThatDoesNotHoldHasNoBranchShowing(): void
    {
        $availability = $this->forms->availability(
            $this->trackForm(),
            $this->slot() + ['track' => 'iskolamisszio'],
            $this->eventFacts()
        );

        self::assertSame([1, null], $availability->chains);
        self::assertFalse($availability->shows('pro_why'));
        self::assertTrue($availability->shows('other_question'));
        self::assertSame(['ifi_a', 'ifi_b', 'iskola_a'], $availability->offeredOptions('modules'));
    }

    public function testAnswersInErrorReadAsEmptyInADraftAndNothingIsRefused(): void
    {
        $availability = $this->forms->availability(
            $this->trackForm(),
            ['track' => 'nincs ilyen', 'first' => 'pro_a'],
            $this->eventFacts()
        );

        self::assertSame([1, null], $availability->chains);
        self::assertSame(['ifi_a', 'ifi_b'], $availability->offeredOptions('first'));
    }

    public function testAtSubmitAnOptionThatIsNotOfferedIsDropped(): void
    {
        $validated = $this->forms->validate($this->trackForm(), $this->slot() + [
            'track' => 'iskolamisszio',
            'other_question' => 'Teszt válasz',
            'modules' => ['pro_a', 'iskola_a', 'ifi_b'],
            'first' => 'iskola_a',
        ]);

        self::assertSame('["ifi_b","iskola_a"]', $validated->stored()['modules']);
        self::assertSame(['iskola_a', 'ifi_b'], $validated->submitted()['modules']);
        self::assertSame('iskola_a', $validated->stored()['first']);
    }

    public function testAtSubmitARequiredSingleChoiceOfAnOptionNotOfferedIsMissing(): void
    {
        try {
            $this->forms->validate($this->trackForm(), $this->slot() + ['track' => 'ifivezeto', 'first' => 'pro_a']);
            self::fail('The answers were accepted.');
        } catch (InvalidAnswers $e) {
            self::assertSame(['first' => 'Ezt a mezőt ki kell töltened.'], $e->fields);
        }
    }

    public function testAnUndeclaredKeyIsRefusedForADraftToo(): void
    {
        $this->expectException(InvalidInput::class);

        $this->forms->availability($this->trackForm(), ['nope' => 'x'], $this->eventFacts());
    }

    /** IVK (S12, S76): under 16 at the event's start, the rest of the form does not show. */
    private function ageForm(): FormDefinition
    {
        $under16 = $this->when('born', 'greaterThan', ['key' => EventValues::START, 'years' => -16]);

        return $this->definition(
            $this->field('date', 'born', ['required' => true])
            . $this->branch('if', $under16, '<!-- wp:paragraph --><p>Teszt</p><!-- /wp:paragraph -->')
            . $this->branch('else', null, $this->field('text', 'motivation', ['required' => true]))
        );
    }

    public function testAConditionComparesADateWithTheEventStartMoved(): void
    {
        $under = $this->forms->availability($this->ageForm(), ['born' => '2011-07-11'], $this->eventFacts());
        $sixteen = $this->forms->availability($this->ageForm(), ['born' => '2011-07-10'], $this->eventFacts());
        $none = $this->forms->availability($this->ageForm(), [], $this->eventFacts());

        self::assertSame([0], $under->chains);
        self::assertFalse($under->shows('motivation'));
        self::assertSame([1], $sixteen->chains);
        self::assertTrue($sixteen->shows('motivation'));
        self::assertSame([1], $none->chains);
        self::assertSame(['born'], $this->ageForm()->triggers);
    }

    public function testTheSubmitReadsTheSameEventFacts(): void
    {
        $validated = $this->forms->validate(
            $this->ageForm(),
            $this->slot() + ['born' => '2011-07-11', 'motivation' => 'Teszt'],
            $this->eventFacts()
        );

        self::assertSame(['name', 'email', 'born'], array_keys($validated->stored()));
    }

    public function testASubmitWithoutTheEventFactsFailsNeverGuesses(): void
    {
        $this->expectException(SystemFailure::class);
        $this->expectExceptionMessage('unknown_value_key event.start');

        $this->forms->validate($this->ageForm(), $this->slot() + ['born' => '2011-07-11']);
    }

    public function testAnOptionConditionReadsOnlyEarlierFields(): void
    {
        $options = [['value' => 'a', 'label' => 'A', 'condition' => $this->when('later', 'empty')]];

        $this->expectException(SystemFailure::class);
        $this->expectExceptionMessage('condition_key_not_earlier later');

        $this->definition($this->field('choice', 'pick', ['options' => $options]) . $this->field('text', 'later'));
    }

    public function testAFormConditionReadsNoUndeclaredDottedKey(): void
    {
        $this->expectException(SystemFailure::class);

        $this->definition($this->branch('if', $this->when('event.nope', 'empty'), $this->field('text', 'x')));
    }

    public function testEventValuesDeclareEveryKeyOfTheirTypes(): void
    {
        $event = new Event(
            1,
            1,
            'Teszt tábor',
            EventState::Published,
            Currency::HUF,
            null,
            false,
            null,
            null,
            new \DateTimeImmutable('2027-07-09 22:00:00', new \DateTimeZone('UTC')),
            null,
            []
        );
        update_option('timezone_string', 'Europe/Budapest');

        $values = EventValues::of($event);

        self::assertSame(array_keys(EventValues::TYPES), array_keys($values));
        foreach (EventValues::TYPES as $key => $type) {
            self::assertSame($type, $values[$key]->type);
        }
        self::assertSame('2027-07-10', $values[EventValues::START]->data);
        self::assertTrue($values[EventValues::END]->isEmpty());
    }
}
