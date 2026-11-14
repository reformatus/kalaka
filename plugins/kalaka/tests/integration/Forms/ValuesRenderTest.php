<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\Api\Values;
use Kalaka\Forms\Engine\Value\RenderStack;
use Kalaka\Foundation\Api\Error\SystemFailure;

/** AC3 and AC4: rendering block markup with a value context (D7, D15, D16, D17). */
final class ValuesRenderTest extends \WP_UnitTestCase
{
    private Values $values;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->values = new Values();
    }

    /** @param array<string, mixed> $condition */
    private function comparison(string $key, string $operator, ?string $value = null): array
    {
        return ['match' => 'all', 'items' => [array_filter(
            ['key' => $key, 'operator' => $operator, 'value' => $value],
            static fn ($part): bool => $part !== null
        )]];
    }

    /** A branch block of a chain, around the markup it holds. */
    private function branch(string $name, ?array $condition, string $inner): string
    {
        $attributes = $condition === null ? '' : ' ' . wp_json_encode(['condition' => $condition]);

        return '<!-- wp:kalaka/' . $name . $attributes . ' -->' . $inner . '<!-- /wp:kalaka/' . $name . ' -->';
    }

    private function paragraph(string $text): string
    {
        return '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
    }

    /**
     * The rendered markup with what WordPress adds on its own (a paragraph's class, whitespace between tags) left
     * out.
     */
    private function render(string $content, ValueContext $context): string
    {
        $html = $this->values->render($content, $context);

        return trim((string) preg_replace('/>\s+</', '><', str_replace(
            [' class="wp-block-paragraph"', '<span >'],
            ['', '<span>'],
            $html
        )));
    }

    /** @return string the markup of a chain over the key `n` (a number), one paragraph per branch */
    private function chain(): string
    {
        return $this->branch('if', $this->comparison('n', 'lessThan', '10'), $this->paragraph('kicsi'))
            . "\n\n"
            . $this->branch('else-if', $this->comparison('n', 'lessThan', '20'), $this->paragraph('közepes'))
            . $this->branch('else-if', $this->comparison('n', 'lessThan', '30'), $this->paragraph('nagy'))
            . "\n"
            . $this->branch('else', null, $this->paragraph('óriási'));
    }

    private function number(string $n): ValueContext
    {
        return new ValueContext(['n' => Value::number($n, $n)]);
    }

    public function testOnlyTheFirstBranchWhoseConditionHoldsIsRenderedWithoutAWrapper(): void
    {
        self::assertSame('<p>kicsi</p>', $this->render($this->chain(), $this->number('5')));
        self::assertSame('<p>közepes</p>', $this->render($this->chain(), $this->number('15')));
        self::assertSame('<p>nagy</p>', $this->render($this->chain(), $this->number('25')));
        self::assertSame('<p>óriási</p>', $this->render($this->chain(), $this->number('35')));
    }

    public function testTheFirstOfSeveralBranchesThatHoldWins(): void
    {
        self::assertSame('<p>kicsi</p>', $this->render($this->chain(), $this->number('-100')));
    }

    public function testWithoutAnElseAChainWhereNothingHoldsRendersNothing(): void
    {
        $chain = $this->branch('if', $this->comparison('n', 'lessThan', '10'), $this->paragraph('kicsi'))
            . $this->branch('else-if', $this->comparison('n', 'lessThan', '20'), $this->paragraph('közepes'));

        self::assertSame('', $this->render($chain, $this->number('50')));
        self::assertSame('', $this->render($chain, new ValueContext(['n' => Value::none(ValueType::Number)])));
    }

    public function testAChainInsideACoreBlockAndAChainInsideABranchBothResolve(): void
    {
        $inner = $this->branch('if', $this->comparison('n', 'greaterThan', '0'), $this->paragraph('pozitív'))
            . $this->branch('else', null, $this->paragraph('nem pozitív'));
        $content = '<!-- wp:group --><div class="wp-block-group">'
            . $this->branch('if', $this->comparison('n', 'lessThan', '100'), $this->paragraph('külső') . $inner)
            . $this->branch('else', null, $this->paragraph('más'))
            . '</div><!-- /wp:group -->';

        $positive = $this->render($content, $this->number('5'));
        self::assertStringStartsWith('<div class="wp-block-group">', $positive);
        self::assertStringContainsString('<p>külső</p><p>pozitív</p></div>', $positive);
        self::assertStringNotContainsString('nem pozitív', $positive);
        self::assertStringContainsString(
            '<p>külső</p><p>nem pozitív</p></div>',
            $this->render($content, $this->number('-5'))
        );
        $other = $this->render($content, $this->number('500'));
        self::assertStringContainsString('<p>más</p></div>', $other);
        self::assertStringNotContainsString('külső', $other);
    }

    public function testABranchThatDoesNotHoldAndHoldsAChainHidesItWhole(): void
    {
        $content = $this->branch(
            'if',
            $this->comparison('n', 'lessThan', '0'),
            $this->branch('if', $this->comparison('n', 'lessThan', '0'), $this->paragraph('rejtett'))
        );

        self::assertSame('', $this->render($content, $this->number('5')));
    }

    public function testACampsChoiceWithoutTheKeyShowsTheElseBranchNotTheTentList(): void
    {
        $content = $this->branch('if', $this->comparison('camps', 'contains', 'k'), $this->paragraph('Sátorlista'))
            . $this->branch('else', null, $this->paragraph('Nincs sátor'));
        $context = new ValueContext(['camps' => Value::choice(['ff'], 'FF')]);

        self::assertSame('<p>Nincs sátor</p>', $this->render($content, $context));
        self::assertSame(
            '<p>Sátorlista</p>',
            $this->render($content, new ValueContext(['camps' => Value::choice(['ff', 'k'], 'FF, K')]))
        );
    }

    /** @return iterable<string, array{string, string}> the markup and the branch that is misplaced */
    public static function orphans(): iterable
    {
        $if = '<!-- wp:kalaka/if {"condition":{"match":"all","items":[{"key":"n","operator":"empty"}]}} -->'
            . '<!-- /wp:kalaka/if -->';
        $elseIf = '<!-- wp:kalaka/else-if {"condition":{"match":"all","items":[{"key":"n","operator":"empty"}]}} -->'
            . '<!-- /wp:kalaka/else-if -->';
        $else = '<!-- wp:kalaka/else --><!-- /wp:kalaka/else -->';
        $paragraph = '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->';

        yield 'else-if alone' => [$elseIf, 'kalaka/else-if'];
        yield 'else alone' => [$else, 'kalaka/else'];
        yield 'else after other markup' => [$if . $paragraph . $else, 'kalaka/else'];
        yield 'else-if after an else' => [$if . $else . $elseIf, 'kalaka/else-if'];
        yield 'second else' => [$if . $else . $else, 'kalaka/else'];
        yield 'else inside a group, after other markup' => [
            '<!-- wp:group --><div class="wp-block-group">' . $if . $paragraph . $else . '</div><!-- /wp:group -->',
            'kalaka/else',
        ];
    }

    /** @dataProvider orphans */
    public function testABranchThatDoesNotFollowAnIfOrElseIfIsAnOrphan(string $content, string $branch): void
    {
        try {
            $this->values->render($content, $this->number('1'));
            self::fail('The misplaced branch was rendered.');
        } catch (SystemFailure $e) {
            self::assertStringContainsString('orphan_branch ' . $branch, $e->getMessage());
        }
        self::assertFalse(RenderStack::isActive());
    }

    private function bound(string $key): string
    {
        return '{"metadata":{"bindings":{"content":{"source":"kalaka/value","args":{"key":"' . $key . '"}}}}}';
    }

    private function context(): ValueContext
    {
        return new ValueContext([
            'name' => Value::text('Kovács Anna', 'Kovács Anna'),
            'markup' => Value::text('<b>x</b>', '<b>x</b>'),
            'link' => Value::text('https://example.org/?a=1&b=2', 'https://example.org/?a=1&b=2'),
            'short' => Value::text('a&b', 'a&b'),
            'nothing' => Value::none(ValueType::Text),
        ]);
    }

    public function testAParagraphContentBoundToTheSourceShowsTheValuesText(): void
    {
        $content = '<!-- wp:paragraph ' . $this->bound('name') . ' --><p>Szerző</p><!-- /wp:paragraph -->';

        self::assertSame('<p>Kovács Anna</p>', $this->render($content, $this->context()));
    }

    public function testATextWithMarkupShowsAsTextInTheParagraph(): void
    {
        $content = '<!-- wp:paragraph ' . $this->bound('markup') . ' --><p>Szerző</p><!-- /wp:paragraph -->';

        self::assertSame('<p>&lt;b&gt;x&lt;/b&gt;</p>', $this->render($content, $this->context()));
    }

    public function testAButtonUrlBoundToTheSourceHasTheValueEscapedOnceInTheHref(): void
    {
        $content = '<!-- wp:button {"metadata":{"bindings":{"url":{"source":"kalaka/value","args":{"key":"link"}}}}}'
            . ' -->'
            . '<div class="wp-block-button">'
            . '<a class="wp-block-button__link wp-element-button" href="https://example.org/">Nyisd meg</a></div>'
            . '<!-- /wp:button -->';

        $html = $this->render($content, $this->context());

        $anchor = new \WP_HTML_Tag_Processor($html);
        self::assertTrue($anchor->next_tag('a'));
        self::assertSame('https://example.org/?a=1&b=2', $anchor->get_attribute('href'));
        self::assertStringNotContainsString('amp;amp;', $html);
        self::assertStringNotContainsString('038;amp;', $html);
        self::assertStringContainsString('>Nyisd meg</a>', $html);
    }

    public function testAnAmpersandInAButtonUrlIsEscapedOnceInTheHref(): void
    {
        $content = '<!-- wp:button {"metadata":{"bindings":{"url":{"source":"kalaka/value","args":{"key":"short"}}}}}'
            . ' -->'
            . '<div class="wp-block-button">'
            . '<a class="wp-block-button__link wp-element-button" href="https://example.org/">Nyisd meg</a></div>'
            . '<!-- /wp:button -->';

        $html = $this->render($content, $this->context());

        // The button block's own URL handling (esc_url) puts a scheme before a value that has none.
        $anchor = new \WP_HTML_Tag_Processor($html);
        self::assertTrue($anchor->next_tag('a'));
        self::assertSame('http://a&b', $anchor->get_attribute('href'));
        self::assertStringNotContainsString('amp;amp;', $html);
        self::assertStringNotContainsString('038;amp;', $html);
    }

    public function testAnInlineSpanInAParagraphAndInCustomHtmlShowsTheValuesTextEscaped(): void
    {
        $span = static fn (string $key): string => '<span data-kalaka-value="' . $key . '">címke</span>';
        $content = '<!-- wp:paragraph --><p>Szia ' . $span('name') . ' és ' . $span('markup')
            . '!</p><!-- /wp:paragraph -->'
            . '<!-- wp:html --><div>' . $span('link') . '</div><!-- /wp:html -->';

        $html = $this->render($content, $this->context());

        self::assertSame(
            '<p>Szia <span>Kovács Anna</span> és <span>&lt;b&gt;x&lt;/b&gt;</span>!</p>'
            . '<div><span>https://example.org/?a=1&amp;b=2</span></div>',
            $html
        );
        self::assertStringNotContainsString('data-kalaka-value', $html);
    }

    public function testAnInlineSpanInsideABranchIsFilledAndOneInAHiddenBranchIsNeverLookedAt(): void
    {
        $span = '<span data-kalaka-value="undeclared">x</span>';
        $content = $this->branch('if', $this->comparison('name', 'empty'), $this->paragraph($span))
            . $this->branch('else', null, $this->paragraph('<span data-kalaka-value="name">x</span>'));

        self::assertSame('<p><span>Kovács Anna</span></p>', $this->render($content, $this->context()));
    }

    public function testADeclaredKeyWithNoValueIsEmptyText(): void
    {
        $content = '<!-- wp:paragraph ' . $this->bound('nothing') . ' --><p>Szerző</p><!-- /wp:paragraph -->'
            . '<!-- wp:paragraph --><p>[<span data-kalaka-value="nothing">címke</span>]</p><!-- /wp:paragraph -->';

        self::assertSame('<p></p><p>[<span></span>]</p>', $this->render($content, $this->context()));
    }

    /** @return iterable<string, array{string}> */
    public static function undeclared(): iterable
    {
        yield 'a binding' => [
            '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"kalaka/value","args":{"key":"other"}}}}} '
                . '--><p>x</p><!-- /wp:paragraph -->',
        ];
        yield 'a binding with no key' => [
            '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"kalaka/value"}}}} -->'
                . '<p>x</p><!-- /wp:paragraph -->',
        ];
        yield 'a span' => [
            '<!-- wp:paragraph --><p><span data-kalaka-value="other">x</span></p><!-- /wp:paragraph -->',
        ];
        yield 'a span with no key' => ['<!-- wp:html --><span data-kalaka-value>x</span><!-- /wp:html -->'];
        yield 'a condition' => [
            '<!-- wp:kalaka/if {"condition":{"match":"all","items":[{"key":"other","operator":"empty"}]}} -->'
                . '<!-- /wp:kalaka/if -->',
        ];
    }

    /** @dataProvider undeclared */
    public function testAKeyTheContextDoesNotDeclareIsAFailure(string $content): void
    {
        try {
            $this->values->render($content, $this->context());
            self::fail('The undeclared key was rendered.');
        } catch (SystemFailure $e) {
            self::assertStringContainsString('unknown_value_key', $e->getMessage());
        }
        self::assertFalse(RenderStack::isActive());
    }

    public function testOutsideTheRendererTheBindingKeepsItsContentTheSpanStaysAndBranchesRenderNothing(): void
    {
        $content = '<!-- wp:paragraph ' . $this->bound('name') . ' --><p>Szerző</p><!-- /wp:paragraph -->'
            . '<!-- wp:paragraph --><p><span data-kalaka-value="name">címke</span></p><!-- /wp:paragraph -->'
            . $this->branch('if', $this->comparison('name', 'empty'), $this->paragraph('benne'))
            . $this->branch('else', null, $this->paragraph('máshol'));

        $html = do_blocks($content);

        self::assertStringContainsString('>Szerző</p>', $html);
        self::assertStringContainsString('<span data-kalaka-value="name">címke</span>', $html);
        self::assertStringNotContainsString('benne', $html);
        self::assertStringNotContainsString('máshol', $html);
    }

    public function testRenderingsNestAndEachReadsItsOwnContext(): void
    {
        $content = '<!-- wp:paragraph --><p><span data-kalaka-value="name">x</span></p><!-- /wp:paragraph -->';

        self::assertSame('<p><span>Kovács Anna</span></p>', $this->render($content, $this->context()));
        self::assertNull(RenderStack::current());
        self::assertSame(
            '<p><span>Nagy Béla</span></p>',
            $this->render($content, new ValueContext(['name' => Value::text('Nagy Béla', 'Nagy Béla')]))
        );
    }

    public function testTheSourceIsRegisteredWithItsHungarianLabel(): void
    {
        $source = get_block_bindings_source('kalaka/value');

        self::assertNotNull($source);
        self::assertSame('Kaláka-érték', $source->label);
    }

    public function testALiteralEntityInAValuesTextIsEscapedInsteadOfPassedThrough(): void
    {
        $context = new ValueContext(['tj' => Value::text('Tom &amp; Jerry', 'Tom &amp; Jerry')]);
        $content = '<!-- wp:paragraph ' . $this->bound('tj') . ' --><p>x</p><!-- /wp:paragraph -->'
            . '<!-- wp:paragraph --><p><span data-kalaka-value="tj">x</span></p><!-- /wp:paragraph -->';

        self::assertSame(
            '<p>Tom &amp;amp; Jerry</p><p><span>Tom &amp;amp; Jerry</span></p>',
            $this->render($content, $context)
        );
    }

    public function testABranchInContentWordPressParsesLaterIsNotReachedByTheChainPassAndRendersNothing(): void
    {
        $pattern = self::factory()->post->create([
            'post_type' => 'wp_block',
            'post_status' => 'publish',
            'post_content' => $this->branch('if', $this->comparison('n', 'lessThan', '10'), $this->paragraph('kicsi'))
                . $this->branch('else', null, $this->paragraph('nagy')),
        ]);
        $content = $this->paragraph('előtte') . '<!-- wp:block {"ref":' . $pattern . '} /-->'
            . $this->paragraph('utána');

        foreach (['5', '50'] as $n) {
            $html = $this->render($content, $this->number($n));

            self::assertStringContainsString('előtte', $html);
            self::assertStringContainsString('utána', $html);
            self::assertStringNotContainsString('kicsi', $html);
            self::assertStringNotContainsString('nagy', $html);
        }
    }
}
