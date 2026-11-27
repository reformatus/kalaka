<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\Api\Values;
use Kalaka\Foundation\Api\Error\SystemFailure;

/** The placeholders typed by hand, `{{key}}` (owner 2026-11-26, N960 option (a)): what renders and what is unknown. */
final class PlaceholdersRenderTest extends \WP_UnitTestCase
{
    private function context(): ValueContext
    {
        return new ValueContext([
            'name' => Value::text('Kovács <Anna>', 'Kovács <Anna>'),
            'link' => Value::text('https://example.org/?a=1&b=2', 'https://example.org/?a=1&b=2'),
            'code' => Value::text('a b&c', 'a b&c'),
            'nothing' => Value::none(ValueType::Text),
            'trap' => Value::text('{{link}}', '{{link}}'),
        ]);
    }

    private function render(string $content, ?ValueContext $context = null): string
    {
        $html = (new Values())->render($content, $context ?? $this->context());

        $html = str_replace([' class="wp-block-paragraph"', '<span >'], ['', '<span>'], $html);

        return trim((string) preg_replace('/>\s+</', '><', $html));
    }

    public function testATypedPlaceholderInTextIsReplacedAndEscaped(): void
    {
        $html = $this->render('<!-- wp:paragraph --><p>Szia {{name}}, {{ name }}!</p><!-- /wp:paragraph -->');

        self::assertSame('<p>Szia Kovács &lt;Anna&gt;, Kovács &lt;Anna&gt;!</p>', $html);
    }

    public function testAKnownKeyWithAnEmptyValueIsAnEmptyString(): void
    {
        $html = $this->render('<!-- wp:paragraph --><p>[{{nothing}}]</p><!-- /wp:paragraph -->');

        self::assertSame('<p>[]</p>', $html);
    }

    public function testAPlaceholderThatIsAWholeUrlAttributeKeepsTheUrlAndEscapesItOnce(): void
    {
        $content = '<!-- wp:button {"url":"{{link}}"} --><div class="wp-block-button">'
            . '<a class="wp-block-button__link wp-element-button" href="{{link}}">{{name}}</a></div>'
            . '<!-- /wp:button -->';

        $html = $this->render($content);

        $anchor = new \WP_HTML_Tag_Processor($html);
        self::assertTrue($anchor->next_tag('a'));
        self::assertSame('https://example.org/?a=1&b=2', $anchor->get_attribute('href'));
        self::assertStringNotContainsString('amp;amp;', $html);
        self::assertStringContainsString('>Kovács &lt;Anna&gt;</a>', $html);
    }

    public function testAPlaceholderInsideALongerUrlIsPercentEncoded(): void
    {
        $html = $this->render('<!-- wp:html --><a href="https://example.org/x?c={{code}}">l</a><!-- /wp:html -->');

        $anchor = new \WP_HTML_Tag_Processor($html);
        self::assertTrue($anchor->next_tag('a'));
        self::assertSame('https://example.org/x?c=a%20b%26c', $anchor->get_attribute('href'));
    }

    public function testAnotherAttributeGetsTheValueAsPlainTextAndAnEventHandlerNothing(): void
    {
        $html = $this->render(
            '<!-- wp:html --><p title="{{name}}" onclick="go(\'{{name}}\')">x</p><!-- /wp:html -->'
        );

        $paragraph = new \WP_HTML_Tag_Processor($html);
        self::assertTrue($paragraph->next_tag('p'));
        self::assertSame('Kovács <Anna>', $paragraph->get_attribute('title'));
        self::assertSame("go('')", $paragraph->get_attribute('onclick'));
    }

    public function testAValuesTextIsNeverReadForAPlaceholder(): void
    {
        $html = $this->render(
            '<!-- wp:paragraph --><p>{{trap}} <span data-kalaka-value="trap">x</span></p><!-- /wp:paragraph -->'
        );

        self::assertSame('<p>{{link}} <span>{{link}}</span></p>', $html);
    }

    public function testAnUnknownKeyIsAFailureAndNeverRendersRaw(): void
    {
        $this->expectException(SystemFailure::class);
        $this->expectExceptionMessage('unknown_value_key foo');

        $this->render('<!-- wp:paragraph --><p>{{foo}}</p><!-- /wp:paragraph -->');
    }

    /**
     * 8.11 a: on a page only a Kaláka block's placeholders are Kaláka's; a `{{key}}` in an ordinary paragraph is
     * left as typed (the save check leaves it alone too).
     */
    public function testOnAPageOnlyAKalakaBlocksPlaceholderIsFilled(): void
    {
        $content = '<!-- wp:paragraph --><p>kint {{name}}</p><!-- /wp:paragraph -->'
            . '<!-- wp:kalaka/if {"condition":{"match":"all","items":[{"key":"nothing","operator":"empty"}]}} -->'
            . '<!-- wp:paragraph --><p>bent {{name}}</p><!-- /wp:paragraph --><!-- /wp:kalaka/if -->';

        $html = (new Values())->renderPage($content, $this->context());
        $html = str_replace(' class="wp-block-paragraph"', '', $html);

        self::assertStringContainsString('<p>kint {{name}}</p>', $html);
        self::assertStringContainsString('<p>bent Kovács &lt;Anna&gt;</p>', $html);
    }

    /** 8.11 a (N1150): an unknown key in a Kaláka block fails the page's whole render, never shows the raw key. */
    public function testOnAPageAnUnknownKeyInAKalakaBlockFailsTheWholeRender(): void
    {
        $content = '<!-- wp:kalaka/if {"condition":{"match":"all","items":[{"key":"nothing","operator":"empty"}]}} -->'
            . '<!-- wp:paragraph --><p>{{foo}}</p><!-- /wp:paragraph --><!-- /wp:kalaka/if -->';

        $this->expectException(SystemFailure::class);
        $this->expectExceptionMessage('unknown_value_key foo');

        (new Values())->renderPage($content, $this->context());
    }

    public function testOnlyTheHeldBranchsPlaceholdersAreLookedAt(): void
    {
        $content = '<!-- wp:kalaka/if {"condition":{"match":"all","items":[{"key":"nothing","operator":"empty"}]}} -->'
            . '<!-- wp:paragraph --><p>{{name}}</p><!-- /wp:paragraph --><!-- /wp:kalaka/if -->'
            . '<!-- wp:kalaka/else --><!-- wp:paragraph --><p>{{foo}}</p><!-- /wp:paragraph -->'
            . '<!-- /wp:kalaka/else -->';

        self::assertSame('<p>Kovács &lt;Anna&gt;</p>', $this->render($content));
    }

    public function testUnknownPlaceholdersAreListedAsTypedEachOnceAndContentIsNeverKnown(): void
    {
        $declared = ['name' => ValueType::Text];
        $content = '<!-- wp:paragraph --><p>{{name}} {{foo}} {{ bar }} {{foo}} {{content}}</p><!-- /wp:paragraph -->'
            . '<!-- wp:button {"url":"{{baz}}"} --><a href="{{qux}}">x</a><!-- /wp:button -->';

        $unknown = (new Values())->unknownPlaceholders($content, $declared);

        self::assertSame(['{{foo}}', '{{ bar }}', '{{content}}', '{{qux}}'], $unknown);
    }

    public function testAPlaceholderSplitByMarkupIsUnknownNotLeaked(): void
    {
        $declared = ['name' => ValueType::Text];

        $unknown = (new Values())->unknownPlaceholders(
            '<!-- wp:paragraph --><p>{{<strong>name</strong>}}</p><!-- /wp:paragraph -->',
            $declared
        );

        self::assertSame(['{{'], $unknown);
    }

    public function testPlacedKeysIncludeTypedPlaceholders(): void
    {
        $keys = (new Values())->placedKeys('<p>{{ a.b }} {{c}} {{a.b}}</p><a href="{{d}}">x</a>');

        self::assertSame(['a.b', 'c', 'd'], $keys);
    }
}
