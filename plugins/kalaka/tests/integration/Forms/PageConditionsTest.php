<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\Values;
use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * #53 N4 (owner 2026-11-13): every block editor offers the condition blocks, and an ordinary page renders
 * them with the visitor's active registration as context; without one no branch renders, and a failing
 * chain or value renders nothing and is logged, never a fatal.
 */
final class PageConditionsTest extends \WP_UnitTestCase
{
    private const CONDITION_BLOCKS = ['kalaka/if', 'kalaka/else-if', 'kalaka/else'];

    private Values $values;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->values = new Values();
    }

    /** @return bool|array<string> */
    private function allowedIn(string $postType): bool|array
    {
        $post = get_post(self::factory()->post->create(['post_type' => $postType]));
        self::assertInstanceOf(\WP_Post::class, $post);

        return get_allowed_block_types(new \WP_Block_Editor_Context(['post' => $post]));
    }

    /** @return array<string, mixed> */
    private function below(string $key, string $value): array
    {
        return ['match' => 'all', 'items' => [['key' => $key, 'operator' => 'lessThan', 'value' => $value]]];
    }

    /** @param array<string, mixed>|null $condition */
    private function branch(string $name, ?array $condition, string $inner): string
    {
        $attributes = $condition === null ? '' : ' ' . wp_json_encode(['condition' => $condition]);

        return '<!-- wp:kalaka/' . $name . $attributes . ' -->' . $inner . '<!-- /wp:kalaka/' . $name . ' -->';
    }

    private function paragraph(string $text): string
    {
        return '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
    }

    /** A chain over the number key given: "kicsi" below 10, "nagy" otherwise. */
    private function chain(string $key): string
    {
        return $this->branch('if', $this->below($key, '10'), $this->paragraph('kicsi'))
            . $this->branch('else', null, $this->paragraph('nagy'));
    }

    private function number(string $n): ValueContext
    {
        return new ValueContext(['n' => Value::number($n, $n)]);
    }

    /** The rendered markup without the paragraph's class and the whitespace between tags. */
    private function clean(string $html): string
    {
        return trim((string) preg_replace('/>\s+</', '><', str_replace(' class="wp-block-paragraph"', '', $html)));
    }

    /** @return array{string, string} what the callback returned and what was logged meanwhile */
    private function logged(callable $callback): array
    {
        $file = tempnam(sys_get_temp_dir(), 'kalaka-log');
        $previous = ini_set('error_log', (string) $file);
        try {
            $result = $callback();
        } finally {
            ini_set('error_log', (string) $previous);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the captured error log, a temporary file
        $log = (string) file_get_contents((string) $file);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removes the captured error log, a temporary file
        unlink((string) $file);

        return [is_string($result) ? $result : '', $log];
    }

    public function testEveryBlockEditorOffersTheConditionBlocksButNotTheFormOnlyOnes(): void
    {
        foreach (['post', 'page', 'kalaka_form'] as $postType) {
            $allowed = $this->allowedIn($postType);

            self::assertIsArray($allowed, $postType);
            self::assertSame([], array_values(array_diff(self::CONDITION_BLOCKS, $allowed)), $postType);
        }
        foreach (['post', 'page'] as $postType) {
            $allowed = $this->allowedIn($postType);

            self::assertIsArray($allowed, $postType);
            self::assertNotContains('kalaka/text-field', $allowed, $postType);
            self::assertNotContains('kalaka/form-page', $allowed, $postType);
            self::assertNotContains('kalaka/after-submit', $allowed, $postType);
        }
    }

    public function testWithoutAnActiveRegistrationNoBranchRendersAndTheRestOfThePageDoes(): void
    {
        $content = $this->paragraph('mindig') . $this->chain('n')
            . '<!-- wp:paragraph --><p><span data-kalaka-value="n">Szám</span></p><!-- /wp:paragraph -->';

        $page = $this->clean($this->values->renderPage($content, null));

        self::assertSame('<p>mindig</p><p><span data-kalaka-value="n">Szám</span></p>', $page);
        self::assertSame($page, $this->clean(do_blocks($content)));
    }

    public function testWithAContextAPageRendersTheHeldBranchAndFillsTheValues(): void
    {
        $content = $this->paragraph('mindig') . $this->chain('n')
            . '<!-- wp:paragraph --><p><span data-kalaka-value="n">Szám</span></p><!-- /wp:paragraph -->';

        self::assertSame(
            '<p>mindig</p><p>kicsi</p><p><span>5</span></p>',
            str_replace('<span >', '<span>', $this->clean($this->values->renderPage($content, $this->number('5'))))
        );
        self::assertStringContainsString(
            '<p>nagy</p>',
            $this->clean($this->values->renderPage($content, $this->number('50')))
        );
    }

    public function testAnUnknownKeyOnAPageRendersNothingAndIsLoggedNeverAFatal(): void
    {
        $content = $this->paragraph('eleje') . $this->chain('ismeretlen') . $this->chain('n')
            . '<!-- wp:paragraph --><p><span data-kalaka-value="ismeretlen">Címke</span></p><!-- /wp:paragraph -->'
            . $this->branch('else', null, $this->paragraph('árva'))
            . $this->paragraph('vége');

        [$page, $log] = $this->logged(fn (): string => $this->values->renderPage($content, $this->number('5')));

        self::assertSame(
            '<p>eleje</p><p>kicsi</p><p><span></span></p><p>vége</p>',
            str_replace('<span >', '<span>', $this->clean($page))
        );
        self::assertStringContainsString('unknown_value_key ismeretlen', $log);
        self::assertStringContainsString('orphan_branch kalaka/else', $log);
        self::assertStringNotContainsString('Címke', $log);
    }

    public function testAnUnknownKeyInAMailBodyStillStopsTheRender(): void
    {
        $this->expectException(SystemFailure::class);
        $this->values->render($this->chain('ismeretlen'), $this->number('5'));
    }
}
