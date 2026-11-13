<?php

declare(strict_types=1);

namespace Kalaka\Forms\Condition;

use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * Finds the chains among sibling blocks (events-and-forms.md, "Conditions"): a `kalaka/if`, then,
 * with nothing but whitespace between, any number of `kalaka/else-if` and at most one
 * `kalaka/else`, which ends it. Pure.
 */
final class Chains
{
    public const IF_BLOCK = 'kalaka/if';
    public const ELSE_IF_BLOCK = 'kalaka/else-if';
    public const ELSE_BLOCK = 'kalaka/else';

    /**
     * The siblings of a container as one sequence: its blocks, and the markup between them as strings.
     * (Top-level blocks need no conversion: `parse_blocks()` already gives the markup between
     * them as blocks without a name.)
     *
     * @param array<string, mixed> $block a parsed block
     * @return list<array<string, mixed>|string>
     */
    public static function sequenceOf(array $block): array
    {
        $blocks = is_array($block['innerBlocks'] ?? null) ? array_values($block['innerBlocks']) : [];
        $sequence = [];
        $index = 0;
        $content = is_array($block['innerContent'] ?? null) ? $block['innerContent'] : [];
        if ($content === []) {
            $content = array_fill(0, count($blocks), null);
        }
        foreach ($content as $chunk) {
            if (is_string($chunk)) {
                $sequence[] = $chunk;
            } elseif (isset($blocks[$index])) {
                $sequence[] = $blocks[$index++];
            }
        }

        /** @var list<array<string, mixed>|string> $sequence */
        return $sequence;
    }

    /**
     * Splits a sequence into its plain items and its chains, in order. The whitespace between the
     * branches of a chain belongs to the chain.
     *
     * @param list<array<string, mixed>|string> $sequence
     * @return list<array<string, mixed>|string|Chain>
     * @throws SystemFailure a branch that does not follow an `if` or `else-if` (problem `orphan_branch`)
     */
    public static function split(array $sequence): array
    {
        $result = [];
        $count = count($sequence);
        for ($i = 0; $i < $count; ++$i) {
            $item = $sequence[$i];
            $name = self::nameOf($item);
            if ($name === self::ELSE_IF_BLOCK || $name === self::ELSE_BLOCK) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a block name, never output
                throw new SystemFailure('orphan_branch ' . $name);
            }
            if ($name !== self::IF_BLOCK || !is_array($item)) {
                $result[] = $item;
                continue;
            }

            $branches = [$item];
            $last = $name;
            while ($last !== self::ELSE_BLOCK) {
                $next = $i + 1;
                while ($next < $count && self::isWhitespace($sequence[$next])) {
                    ++$next;
                }
                $nextItem = $sequence[$next] ?? null;
                $nextName = self::nameOf($nextItem);
                if (!is_array($nextItem) || ($nextName !== self::ELSE_IF_BLOCK && $nextName !== self::ELSE_BLOCK)) {
                    break;
                }
                $branches[] = $nextItem;
                $last = $nextName;
                $i = $next;
            }
            $result[] = new Chain($branches);
        }

        return $result;
    }

    /** @param array<string, mixed>|string|null $item */
    private static function nameOf(array|string|null $item): ?string
    {
        return is_array($item) && is_string($item['blockName'] ?? null) ? $item['blockName'] : null;
    }

    /** @param array<string, mixed>|string $item markup of nothing but whitespace, or a nameless block of it */
    private static function isWhitespace(array|string $item): bool
    {
        if (is_array($item)) {
            if (isset($item['blockName'])) {
                return false;
            }
            $item = is_string($item['innerHTML'] ?? null) ? $item['innerHTML'] : '';
        }

        return preg_match('/\A[\s\p{Z}]*\z/u', $item) === 1;
    }
}
