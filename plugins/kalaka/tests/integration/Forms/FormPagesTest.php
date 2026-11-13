<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\FormTerminal;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\Api\Values;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * Multi-page forms (MP-1 to MP-13): the page block, its rules, `pages` and the page's errors in the
 * availability, the walk dropping a skipped page's answers, the terminal page refused at submit, the page
 * markup and the after-submit block left out of the form.
 */
final class FormPagesTest extends FormsTestCase
{
    /** @param array<string, mixed> $attributes */
    private function page(string $inner, array $attributes = []): string
    {
        return '<!-- wp:kalaka/form-page' . ($attributes === [] ? '' : ' ' . wp_json_encode($attributes)) . ' -->'
            . $inner . '<!-- /wp:kalaka/form-page -->';
    }

    /** @return array<string, mixed> */
    private function when(string $key, string $value): array
    {
        return ['match' => 'all', 'items' => [['key' => $key, 'operator' => 'equals', 'value' => $value]]];
    }

    private function mode(): string
    {
        return $this->field('choice', 'mode', ['required' => true, 'options' => [
            ['value' => 'camp', 'label' => 'Tábor'],
            ['value' => 'day', 'label' => 'Napközi'],
            ['value' => 'young', 'label' => 'Fiatal'],
        ]]);
    }

    /**
     * Page 0: the basics and the mode; page 1 for camp (a required room and a chain); page 2 for day;
     * page 3 terminal for young; page 4 for everyone.
     */
    private function content(): string
    {
        $note = ['match' => 'all', 'items' => [['key' => 'room', 'operator' => 'empty']]];

        return $this->page($this->slotFields() . $this->mode(), ['label' => 'Alapadatok'])
            . $this->page(
                $this->field('text', 'room', ['required' => true])
                . '<!-- wp:kalaka/if ' . wp_json_encode(['condition' => $note]) . ' -->'
                . $this->field('text', 'why')
                . '<!-- /wp:kalaka/if -->',
                ['label' => 'Szállás', 'condition' => $this->when('mode', 'camp')]
            )
            . $this->page($this->field('text', 'arrival', ['required' => true]), [
                'condition' => $this->when('mode', 'day'),
            ])
            . $this->page('<!-- wp:paragraph --><p>Csak 14 éves kortól.</p><!-- /wp:paragraph -->', [
                'label' => 'Sajnáljuk',
                'terminal' => true,
                'condition' => $this->when('mode', 'young'),
            ])
            . $this->page($this->field('text', 'note'));
    }

    private function definition(string $content): FormDefinition
    {
        return $this->forms->definition($this->readForm($this->insertForm($content)));
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
    private function basics(string $mode): array
    {
        return ['name' => 'Teszt Elek', 'email' => 'teszt@example.org', 'mode' => $mode];
    }

    private function assertProblem(string $content, string $code): void
    {
        try {
            $this->definition($content);
            self::fail('Problem ' . $code . ' was not found.');
        } catch (SystemFailure $e) {
            self::assertStringContainsString($code, $e->getMessage());
        }
    }

    public function testTheDefinitionHasThePagesInOrderAndThePageConditionsAreTriggers(): void
    {
        $definition = $this->definition($this->content());

        self::assertCount(5, $definition->pages);
        self::assertSame([0, 1, 2, 3, 4], array_map(static fn ($page): int => $page->index, $definition->pages));
        self::assertSame('Alapadatok', $definition->pages[0]->label);
        self::assertNull($definition->pages[0]->condition);
        self::assertTrue($definition->pages[3]->terminal);
        self::assertSame(['mode', 'room'], $definition->triggers);
        self::assertSame(0, $definition->field('mode')?->page);
        self::assertSame(1, $definition->field('why')?->page);
        self::assertSame(1, $definition->chains[0]->page);
        self::assertSame(4, $definition->field('note')?->page);
    }

    public function testAFormWithoutPagesHasNone(): void
    {
        $definition = $this->definition($this->slotFields());

        self::assertSame([], $definition->pages);
        self::assertNull($definition->field('name')?->page);
        $availability = $this->forms->availability($definition, [], $this->facts());
        self::assertSame([], $availability->pages);
        self::assertSame([], $availability->toArray()['pages']);
    }

    public function testThePageRulesAreDefinitionProblems(): void
    {
        $this->assertProblem(
            $this->page($this->slotFields()) . $this->field('text', 'outside'),
            'content_outside_pages'
        );
        $this->assertProblem(
            $this->page($this->slotFields() . $this->page($this->field('text', 'inner'))),
            'page_not_top_level'
        );
        $this->assertProblem(
            $this->page($this->slotFields(), ['condition' => $this->when('event.name', 'x')]),
            'first_page_condition'
        );
        $this->assertProblem(
            $this->page($this->slotFields()) . $this->page('', ['terminal' => true]),
            'terminal_page_unconditional'
        );
        $this->assertProblem(
            $this->page($this->slotFields() . $this->mode())
            . $this->page($this->field('text', 'x'), [
                'terminal' => true,
                'condition' => $this->when('mode', 'young'),
            ]),
            'field_on_terminal_page'
        );
        $this->assertProblem(
            $this->page($this->field('name', 'name', ['displayName' => true]) . $this->mode())
            . $this->page($this->field('email', 'email', ['contactEmail' => true]), [
                'condition' => $this->when('mode', 'camp'),
            ]),
            'marking_on_conditional_page'
        );
        $after = '<!-- wp:kalaka/after-submit --><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->'
            . '<!-- /wp:kalaka/after-submit -->';
        $this->assertProblem($this->slotFields() . $after . $after, 'after_submit_count');
        $this->assertProblem(
            $this->slotFields() . '<!-- wp:kalaka/after-submit -->' . $this->field('text', 'x')
            . '<!-- /wp:kalaka/after-submit -->',
            'field_in_after_submit'
        );
        $this->assertProblem(
            $this->page($this->slotFields() . $after),
            'after_submit_not_top_level'
        );
    }

    public function testAPageConditionReadsOnlyEarlierPages(): void
    {
        $this->assertProblem(
            $this->page($this->slotFields())
            . $this->page($this->mode(), ['condition' => $this->when('mode', 'camp')]),
            'condition_key_not_earlier mode'
        );
    }

    public function testTheAvailabilityHasThePagesThatShowAndASkippedPagesFieldsAndChainsDoNot(): void
    {
        $definition = $this->definition($this->content());

        $camp = $this->forms->availability($definition, $this->basics('camp'), $this->facts());
        self::assertSame([true, true, false, false, true], $camp->pages);
        self::assertTrue($camp->fields['room']);
        self::assertFalse($camp->fields['arrival']);
        self::assertSame([0], $camp->chains);

        $day = $this->forms->availability($definition, $this->basics('day') + ['room' => ''], $this->facts());
        self::assertSame([true, false, true, false, true], $day->pages);
        self::assertFalse($day->fields['room']);
        self::assertFalse($day->fields['why']);
        self::assertSame([null], $day->chains);

        $none = $this->forms->availability($definition, [], $this->facts());
        self::assertSame([true, false, false, false, true], $none->pages);
    }

    public function testAPagesErrorsComeOnlyWhenThePageIsAskedFor(): void
    {
        $definition = $this->definition($this->content());

        $typing = $this->forms->availability($definition, ['mode' => 'camp'], $this->facts());
        self::assertSame([], $typing->errors);
        self::assertEquals(new \stdClass(), $typing->toArray()['errors']);

        $first = $this->forms->availability($definition, ['mode' => 'camp'], $this->facts(), 0);
        self::assertSame(['name', 'email'], array_keys($first->errors));
        self::assertSame('Ezt a mezőt ki kell töltened.', $first->errors['name']);

        $second = $this->forms->availability($definition, $this->basics('camp'), $this->facts(), 1);
        self::assertSame(['room'], array_keys($second->errors));

        // A page that does not show, and a terminal page, have no errors.
        $skipped = $this->forms->availability($definition, $this->basics('camp'), $this->facts(), 2);
        self::assertSame([], $skipped->errors);
        $terminal = $this->forms->availability($definition, $this->basics('young'), $this->facts(), 3);
        self::assertSame([], $terminal->errors);
    }

    public function testAPageOutOfRangeOrAPageOfAFormWithoutPagesIsInvalidInput(): void
    {
        foreach ([[$this->content(), 5], [$this->content(), -1], [$this->slotFields(), 0]] as [$content, $page]) {
            try {
                $this->forms->availability($this->definition($content), [], $this->facts(), $page);
                self::fail('Page ' . $page . ' was accepted.');
            } catch (InvalidInput) {
                self::assertTrue(true);
            }
        }
    }

    public function testAtSubmitASkippedPageIsNotRequiredAndItsAnswersAreDropped(): void
    {
        $definition = $this->definition($this->content());

        $valid = $this->forms->validate(
            $definition,
            $this->basics('day') + ['arrival' => 'reggel', 'room' => 'Kati'],
            $this->facts()
        );

        self::assertArrayNotHasKey('room', $valid->stored());
        self::assertSame('reggel', $valid->stored()['arrival']);

        $this->expectException(InvalidAnswers::class);
        $this->forms->validate($definition, $this->basics('camp'), $this->facts());
    }

    public function testASubmitWhileATerminalPageShowsIsRefusedWithItsOwnCode(): void
    {
        $definition = $this->definition($this->content());

        try {
            $this->forms->validate($definition, $this->basics('young'), $this->facts());
            self::fail('The terminal submit should have been refused.');
        } catch (FormTerminal $terminal) {
            self::assertSame('kalaka_form_terminal', $terminal->errorCode());
            self::assertSame(403, $terminal->status());
            self::assertSame('Ez az űrlap most nem küldhető be.', $terminal->getMessage());
            self::assertFalse($terminal->isAudited());
        }
    }

    public function testEveryPageRendersHiddenWithItsNumberTitleAndTerminalMark(): void
    {
        $form = $this->readForm($this->insertForm($this->content() . '<!-- wp:kalaka/after-submit -->'
            . '<!-- wp:paragraph --><p>Köszönjük</p><!-- /wp:paragraph --><!-- /wp:kalaka/after-submit -->'));

        $html = implode('', array_map('render_block', $this->forms->blocks($form)));

        preg_match_all('/<div data-kalaka-page="[^>]*>/', $html, $pages);
        self::assertSame([
            '<div data-kalaka-page="0" hidden>',
            '<div data-kalaka-page="1" hidden>',
            '<div data-kalaka-page="2" hidden>',
            '<div data-kalaka-page="3" data-kalaka-terminal hidden>',
            '<div data-kalaka-page="4" hidden>',
        ], $pages[0]);
        $title = '<div data-kalaka-page="0" hidden><h2 data-kalaka-page-title>Alapadatok</h2>';
        self::assertStringContainsString($title, $html);
        self::assertStringContainsString('<div data-kalaka-page="2" hidden><div class="kalaka-field', $html);
        self::assertStringNotContainsString('kalakaPage', $html);
        self::assertStringNotContainsString('Köszönjük', $html);
    }

    public function testOutsideAFormPageAPageAndTheAfterSubmitBlockRenderNothingOfTheForm(): void
    {
        $html = (new Values())->render(
            $this->page('<!-- wp:paragraph --><p>Lapon</p><!-- /wp:paragraph -->'),
            new ValueContext()
        );

        self::assertSame('', trim($html));
    }
}
