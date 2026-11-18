<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use WP_UnitTestCase;

/**
 * The neutral base of Kaláka's own controls (engineering.md, "WordPress surfaces"): in the `kalaka` cascade
 * layer, every selector a zero-specificity `:where()`, no `!important`, and the documented custom properties.
 */
final class BaseStylesheetTest extends WP_UnitTestCase
{
    /** The `@layer kalaka { … }` block of a stylesheet. */
    private static function layer(string $css): string
    {
        $start = strpos($css, '@layer kalaka {');
        self::assertNotFalse($start, 'no kalaka layer');

        return substr($css, $start);
    }

    public function testTheFormsBaseIsLayeredWithZeroSpecificityAndNoImportant(): void
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local source file
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Forms/Blocks/form/view.css');
        $layer = self::layer($css);

        self::assertStringNotContainsString('!important', $layer);
        // Every rule's selector (the text before each `{` that is not an at-rule) starts with `:where(`.
        $withoutComments = (string) preg_replace('#/\*.*?\*/#s', '', substr($layer, strlen('@layer kalaka {')));
        preg_match_all('/([^{};]+)\{/', $withoutComments, $selectors);
        self::assertNotEmpty($selectors[1]);
        foreach ($selectors[1] as $selector) {
            $selector = trim($selector);
            if (!str_starts_with($selector, '@')) {
                self::assertStringStartsWith(':where(', $selector, $selector);
            }
        }
        foreach (
            [
                '--kalaka-border-color',
                '--kalaka-radius',
                '--kalaka-control-background',
                '--kalaka-control-color',
                '--kalaka-control-hover-background',
                '--kalaka-disabled-opacity',
            ] as $property
        ) {
            self::assertStringContainsString('var(' . $property . ',', $layer, $property);
            self::assertStringContainsString('`' . $property . '`', $css, 'documented: ' . $property);
        }
        self::assertStringContainsString('font: inherit;', $layer);
    }

    public function testATextFieldsLabelSitsAboveItsControlOutsideTheLayerAndAConsentsLabelIsLeftAlone(): void
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local source file
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Forms/Blocks/form/view.css');
        $structure = substr($css, 0, (int) strpos($css, '@layer kalaka {'));

        // Structure, so no theme's look outweighs it; only a `label` that names a control (`for`), anywhere in
        // the field (an extension's field too).
        self::assertMatchesRegularExpression(
            '/form\[data-wp-interactive="kalaka\/form"\] \.kalaka-field label\[for\] \{\s*display: block;\s*\}/',
            $structure
        );
    }

    public function testTheFilledControlsPropertiesAreDocumented(): void
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local source file
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Forms/Blocks/form/view.css');

        $properties = ['--kalaka-button-background', '--kalaka-button-color', '--kalaka-button-hover-background'];
        foreach ($properties as $property) {
            self::assertStringContainsString('`' . $property . '`', $css, 'documented: ' . $property);
        }
    }
}
