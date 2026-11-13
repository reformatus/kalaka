<?php

declare(strict_types=1);

namespace Kalaka\Forms\Value;

use Kalaka\Forms\Api\ValueContext;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * The contexts of the `Values::render()` and `Values::renderPage()` calls in progress, innermost last:
 * what the branch blocks and the `kalaka/value` source read while a document renders, each with whether
 * it is a page (`Values::renderPage()`). Empty outside those calls.
 */
final class RenderStack
{
    /** The attribute `ChainPass` sets on the branch block it resolved as held. */
    public const HELD_ATTRIBUTE = 'kalakaHeld';

    /** @var list<array{ValueContext, bool}> context, page */
    private static array $contexts = [];

    public static function push(ValueContext $context, bool $page = false): void
    {
        self::$contexts[] = [$context, $page];
    }

    public static function pop(): void
    {
        array_pop(self::$contexts);
    }

    public static function current(): ?ValueContext
    {
        return self::$contexts === [] ? null : self::$contexts[array_key_last(self::$contexts)][0];
    }

    /** Whether the innermost render in progress is a page's (`Values::renderPage()`). */
    public static function isPage(): bool
    {
        return self::$contexts !== [] && self::$contexts[array_key_last(self::$contexts)][1];
    }

    /**
     * On a page, a value or condition that fails (a key the context does not declare, an invalid
     * condition, a misplaced branch) renders nothing and is logged here, never a fatal.
     */
    public static function reportOnPage(SystemFailure $failure): void
    {
        Boundary::report('Forms: a value or condition on a page', $failure);
    }

    public static function isActive(): bool
    {
        return self::$contexts !== [];
    }

    /**
     * Whether a branch block renders its inner blocks: only the held branch the chain pass resolved, while
     * a `Values::render()` or `Values::renderPage()` call is in progress. A branch WordPress parses later
     * during the render (a synced pattern, say) was not resolved and renders nothing.
     */
    public static function rendersBranch(\WP_Block $block): bool
    {
        return self::isActive() && ($block->parsed_block['attrs'][self::HELD_ATTRIBUTE] ?? false) === true;
    }
}
