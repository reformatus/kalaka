<?php

declare(strict_types=1);

namespace Kalaka\Forms\Value;

use Kalaka\Forms\Api\ValueContext;

/**
 * The contexts of the `Values::render()` calls in progress, innermost last: what the branch blocks
 * and the `kalaka/value` source read while a document renders. Empty outside `Values::render()`.
 */
final class RenderStack
{
    /** The attribute `ChainPass` sets on the branch block it resolved as held. */
    public const HELD_ATTRIBUTE = 'kalakaHeld';

    /** @var list<ValueContext> */
    private static array $contexts = [];

    public static function push(ValueContext $context): void
    {
        self::$contexts[] = $context;
    }

    public static function pop(): void
    {
        array_pop(self::$contexts);
    }

    public static function current(): ?ValueContext
    {
        return self::$contexts === [] ? null : self::$contexts[array_key_last(self::$contexts)];
    }

    public static function isActive(): bool
    {
        return self::$contexts !== [];
    }

    /**
     * Whether a branch block renders its inner blocks: only the held branch the chain pass resolved, while
     * a `Values::render()` call is in progress. A branch WordPress parses later during the render (a synced
     * pattern, say) was not resolved and renders nothing.
     */
    public static function rendersBranch(\WP_Block $block): bool
    {
        return self::isActive() && ($block->parsed_block['attrs'][self::HELD_ATTRIBUTE] ?? false) === true;
    }
}
