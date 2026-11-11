<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\EventState;
use Kalaka\Forms\Api\AfterSubmit;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\Value\RenderStack;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Money\Currency;

/**
 * #156 follow-ups: a chain waits for a required single choice (N10), branches on held facts are
 * withheld from the page (N11), and the after-submit section (N12).
 */
final class FormRulesTest extends FormsTestCase
{
    /** @param array<string, mixed>|null $condition */
    private function branch(string $name, ?array $condition, string $inner): string
    {
        return '<!-- wp:kalaka/' . $name . ($condition === null ? '' : ' '
            . wp_json_encode(['condition' => $condition]))
            . ' -->' . $inner . '<!-- /wp:kalaka/' . $name . ' -->';
    }

    /** @return array<string, mixed> */
    private function when(string $key, string $operator, mixed $value = null, bool $not = false): array
    {
        $item = ['key' => $key, 'operator' => $operator] + ($value === null ? [] : ['value' => $value]);

        return ['match' => 'all', 'items' => [$item + ($not ? ['not' => true] : [])]];
    }

    private function definition(string $content): FormDefinition
    {
        return $this->forms->definition($this->readForm($this->slotForm($content)));
    }

    private function facts(): ValueContext
    {
        return new ValueContext([
            EventValues::NAME => Value::text('Teszt tábor', 'Teszt tábor'),
            EventValues::REGISTRATION_OPENS => Value::none(ValueType::Date),
            EventValues::REGISTRATION_CLOSES => Value::none(ValueType::Date),
            EventValues::START => Value::date('2027-07-10', '2027-07-10'),
            EventValues::END => Value::none(ValueType::Date),
        ]);
    }

    /** @return array<string, mixed> */
    private function slot(): array
    {
        return ['name' => 'Teszt Elek', 'email' => 'teszt@example.org'];
    }

    /** A track choice and a chain on it: `if track = pro` (a required question), else another question. */
    private function trackChain(bool $required, bool $multiple = false): string
    {
        $tracks = [['value' => 'alap', 'label' => 'Alap'], ['value' => 'pro', 'label' => 'PRO']];

        $attributes = ['options' => $tracks, 'required' => $required, 'multiple' => $multiple];

        return $this->field('choice', 'track', $attributes)
            . $this->branch(
                'if',
                $this->when('track', 'contains', 'pro'),
                $this->field('text', 'pro_question', ['required' => true])
            )
            . $this->branch('else', null, $this->field('text', 'other_question'));
    }

    public function testARequiredSingleChoiceUnansweredShowsNoBranchOfTheChainReadingIt(): void
    {
        $definition = $this->definition($this->trackChain(true));

        $draft = $this->forms->availability($definition, $this->slot(), $this->facts());
        self::assertSame([null], $draft->chains);
        self::assertFalse($draft->shows('pro_question'));
        self::assertFalse($draft->shows('other_question'));

        $answered = $this->forms->availability($definition, $this->slot() + ['track' => 'alap'], $this->facts());
        self::assertSame([1], $answered->chains);
        self::assertTrue($answered->shows('other_question'));

        // An option that is not one of the choice's is no valid answer: still pending.
        $wrong = $this->forms->availability($definition, $this->slot() + ['track' => ''], $this->facts());
        self::assertSame([null], $wrong->chains);
    }

    /** @return iterable<string, array{bool, bool}> required, multiple */
    public static function emptyReadsAsAnswered(): iterable
    {
        yield 'an optional single choice' => [false, false];
        yield 'an optional multiple choice' => [false, true];
        yield 'a required multiple choice' => [true, true];
    }

    /** @dataProvider emptyReadsAsAnswered */
    public function testAnyOtherUnansweredChoiceReadsAsEmptyAndTheElseShows(bool $required, bool $multiple): void
    {
        $draft = $this->forms->availability(
            $this->definition($this->trackChain($required, $multiple)),
            $this->slot(),
            $this->facts()
        );

        self::assertSame([1], $draft->chains);
        self::assertTrue($draft->shows('other_question'));
    }

    public function testAnUntickedConsentReadsAsEmptyAndTheElseShows(): void
    {
        $definition = $this->definition(
            $this->field('consent', 'photos')
            . $this->branch('if', $this->when('photos', 'empty', null, true), $this->field('text', 'photo_note'))
            . $this->branch('else', null, $this->field('text', 'no_photo_note'))
        );

        $draft = $this->forms->availability($definition, $this->slot(), $this->facts());

        self::assertSame([1], $draft->chains);
        self::assertTrue($draft->shows('no_photo_note'));
        $ticked = $this->forms->availability($definition, $this->slot() + ['photos' => true], $this->facts());
        self::assertSame([0], $ticked->chains);
    }

    public function testAChainReadingSeveralFieldsWaitsWhileAnyDecidingOneIsUnanswered(): void
    {
        $tracks = [['value' => 'alap', 'label' => 'Alap'], ['value' => 'pro', 'label' => 'PRO']];
        $either = [
            'match' => 'any',
            'items' => [
                ['key' => 'track', 'operator' => 'equals', 'value' => 'pro'],
                ['key' => 'note', 'operator' => 'empty', 'not' => true],
            ],
        ];
        $definition = $this->definition(
            $this->field('choice', 'track', ['options' => $tracks, 'required' => true])
            . $this->field('text', 'note')
            . $this->branch('if', $either, $this->field('text', 'extra'))
            . $this->branch('else', null, $this->field('text', 'other'))
        );

        $pending = $this->forms->availability($definition, $this->slot() + ['note' => 'Teszt'], $this->facts());
        self::assertSame([null], $pending->chains);
        $decided = $this->forms->availability($definition, $this->slot() + ['track' => 'alap'], $this->facts());
        self::assertSame([1], $decided->chains);
    }

    public function testAtSubmitAPendingChainsFieldsAreNotRequiredAndHiddenAnswersAreDropped(): void
    {
        $definition = $this->definition($this->trackChain(true));

        try {
            $this->forms->validate($definition, $this->slot() + ['pro_question' => 'Teszt'], $this->facts());
            self::fail('The missing track should have been refused.');
        } catch (InvalidAnswers $invalid) {
            self::assertSame(['track'], array_keys($invalid->fields));
        }

        // The browser still holds the answer of the branch the person left: it is dropped.
        $validated = $this->forms->validate(
            $definition,
            $this->slot() + ['track' => 'alap', 'pro_question' => 'Teszt', 'other_question' => 'Más'],
            $this->facts()
        );
        self::assertArrayNotHasKey('pro_question', $validated->stored());
        self::assertArrayNotHasKey('pro_question', $validated->submitted());
        self::assertSame('Más', $validated->stored()['other_question']);
    }

    public function testABranchOnAHeldFactShipsOnlyWhenItHolds(): void
    {
        $content = $this->field('text', 'note')
            . $this->branch(
                'if',
                $this->when(EventValues::NAME, 'empty', null, true),
                $this->field('text', 'insider')
            )
            . $this->branch('else', null, $this->field('text', 'outsider'))
            . $this->branch('if', $this->when('note', 'empty'), $this->field('text', 'empty_note'));
        $form = $this->readForm($this->slotForm($content));
        $definition = $this->forms->definition($form);
        self::assertSame([0], $definition->withheldChains());

        $availability = $this->forms->availability($definition, $this->slot(), $this->facts());
        self::assertSame([0], $availability->toArray()['withheld']);
        $html = implode('', array_map('render_block', $this->forms->blocks($form, null, $availability)));

        self::assertStringContainsString('<div data-kalaka-chain="0" data-kalaka-branch="0">', $html);
        self::assertStringContainsString('data-kalaka-field="insider"', $html);
        self::assertStringNotContainsString('outsider', $html);
        self::assertStringContainsString('<div data-kalaka-chain="1" data-kalaka-branch="0" hidden>', $html);

        $none = implode('', array_map('render_block', $this->forms->blocks($form)));
        self::assertStringNotContainsString('insider', $none);
        self::assertStringNotContainsString('outsider', $none);
        self::assertStringContainsString('data-kalaka-field="empty_note"', $none);
    }

    /** A form page that renders its marked blocks inside a value context still gets the branch wrappers. */
    public function testMarkedBranchesRenderTheirWrappersInsideAValueContext(): void
    {
        $form = $this->readForm($this->slotForm($this->trackChain(true)));

        RenderStack::push($this->facts());
        try {
            $html = implode('', array_map('render_block', $this->forms->blocks($form)));
        } finally {
            RenderStack::pop();
        }

        self::assertStringContainsString('<div data-kalaka-chain="0" data-kalaka-branch="0" hidden>', $html);
        self::assertStringContainsString('<div data-kalaka-chain="0" data-kalaka-branch="1" hidden>', $html);
        self::assertStringContainsString('data-kalaka-field="pro_question"', $html);
    }

    private function event(): Event
    {
        return new Event(
            7,
            1,
            'Teszt tábor',
            EventState::Published,
            Currency::HUF,
            41,
            false,
            null,
            null,
            new \DateTimeImmutable('2027-07-05 00:00:00', wp_timezone()),
            null,
            []
        );
    }

    /** An after-submit section with an inline code and a chain on the status. */
    private function afterSubmitSection(): string
    {
        $paragraph = static fn (string $html): string
            => '<!-- wp:paragraph --><p>' . $html . '</p><!-- /wp:paragraph -->';

        return '<!-- wp:kalaka/after-submit -->'
            . $paragraph('Kód: <span data-kalaka-value="' . AfterSubmit::CODE . '">kód</span>')
            . $this->branch(
                'if',
                $this->when(AfterSubmit::STATUS, 'contains', 'waitlisted'),
                $paragraph('Várólista')
            )
            . $this->branch('else', null, $paragraph('Bent vagy'))
            . '<!-- /wp:kalaka/after-submit -->';
    }

    public function testTheAfterSubmitSectionRendersWithTheRegistrationsCodeAndStatusAndNeverInTheForm(): void
    {
        $form = $this->readForm($this->slotForm($this->trackChain(true) . $this->afterSubmitSection()));
        $definition = $this->forms->definition($form);
        self::assertCount(1, $definition->chains);

        $registered = $this->forms->afterSubmit(
            $form,
            $form->version,
            AfterSubmit::context($this->event(), '00A5', 'registered')
        );
        $waitlisted = $this->forms->afterSubmit(
            $form,
            $form->version,
            AfterSubmit::context($this->event(), '00B3', 'waitlisted')
        );

        self::assertIsString($registered);
        self::assertStringContainsString('00A5', $registered);
        self::assertStringContainsString('Bent vagy', $registered);
        self::assertStringNotContainsString('Várólista', $registered);
        self::assertIsString($waitlisted);
        self::assertStringContainsString('00B3', $waitlisted);
        self::assertStringContainsString('Várólista', $waitlisted);

        $page = implode('', array_map('render_block', $this->forms->blocks($form)));
        self::assertStringNotContainsString('Bent vagy', $page);
        self::assertStringNotContainsString('Kód', $page);
    }

    public function testAFormWithoutTheSectionHasNoAfterSubmitContent(): void
    {
        $form = $this->readForm($this->slotForm());

        $context = AfterSubmit::context($this->event(), '00A5', 'registered');

        self::assertNull($this->forms->afterSubmit($form, $form->version, $context));
    }

    /** @return iterable<string, array{string, string}> the content after the slot fields, the problem */
    public static function badSections(): iterable
    {
        $section = static fn (string $inner): string
            => '<!-- wp:kalaka/after-submit -->' . $inner . '<!-- /wp:kalaka/after-submit -->';

        yield 'two sections' => [$section('') . $section(''), 'after_submit_count'];
        yield 'a field inside' => [
            $section('<!-- wp:kalaka/text-field {"key":"note","label":"Megjegyzés"} /-->'),
            'field_in_after_submit',
        ];
        yield 'a condition on an answer' => [
            $section('<!-- wp:kalaka/if {"condition":{"match":"all","items":[{"key":"name","operator":"empty"}]}} -->'
                . '<!-- /wp:kalaka/if -->'),
            'unknown_value_key name',
        ];
        yield 'inside a branch' => [
            '<!-- wp:kalaka/if {"condition":{"match":"all","items":[{"key":"name","operator":"empty"}]}} -->'
                . $section('') . '<!-- /wp:kalaka/if -->',
            'after_submit_in_branch',
        ];
    }

    /** @dataProvider badSections */
    public function testAMisplacedOrMisbuiltSectionMakesTheFormUnusable(string $content, string $problem): void
    {
        $this->expectException(SystemFailure::class);
        $this->expectExceptionMessage($problem);

        $this->definition($content);
    }
}
