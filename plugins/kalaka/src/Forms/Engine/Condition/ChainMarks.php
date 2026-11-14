<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\Condition;

use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * The one numbering of a form's chains: every branch block gets its chain's number and its own index
 * in the chain as attributes. Chains are numbered from 0 in document order, depth-first, a chain
 * before the chains inside its branches. `DefinitionParser` reads the numbers from these attributes,
 * and the branch blocks render them (`data-kalaka-chain`, `data-kalaka-branch`), so the definition,
 * `FormAvailability::$chains` and the page's markup cannot drift apart. Pure.
 */
final class ChainMarks
{
    public const CHAIN_ATTRIBUTE = 'kalakaChain';

    public const BRANCH_ATTRIBUTE = 'kalakaBranch';

    /** The attribute a top-level page block is marked with: its page number (MP-3). */
    public const PAGE_ATTRIBUTE = 'kalakaPage';

    /** The page block; only a top-level one is numbered. */
    public const PAGE_BLOCK = 'kalaka/form-page';

    /** The after-submit section (`Api\AfterSubmit`): at most one, at the top level; its chains are not the form's. */
    public const AFTER_SUBMIT_BLOCK = 'kalaka/after-submit';

    private int $next = 0;

    /**
     * @param list<array<string, mixed>> $blocks parsed blocks of a whole document (`parse_blocks()`)
     * @return list<array<string, mixed>> the same blocks, every branch block marked
     * @throws SystemFailure a branch that does not follow an `if` or `else-if` (`orphan_branch`)
     */
    public static function mark(array $blocks): array
    {
        // Pages are numbered from 0 in document order, at the top level only.
        $page = 0;
        foreach ($blocks as $index => $block) {
            if (($block['blockName'] ?? null) === self::PAGE_BLOCK) {
                $attributes = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                $blocks[$index]['attrs'] = [...$attributes, self::PAGE_ATTRIBUTE => $page++];
            }
        }

        $marks = new self();
        $marked = [];
        foreach ($marks->sequence($blocks) as $item) {
            if (is_array($item)) {
                $marked[] = $item;
            }
        }

        return $marked;
    }

    /** Set by `prune()` on the branch of a withheld chain it keeps: that branch shows. */
    public const SHOWN_ATTRIBUTE = 'kalakaShown';

    /**
     * Withholds branches from marked blocks (N11): of each chain in `$withheld` only the branch given
     * there stays, marked shown; the chain's other branches, and everything inside them, are dropped. A
     * chain withheld with null keeps no branch.
     *
     * @param list<array<string, mixed>> $blocks blocks `mark()` returned
     * @param array<int, int|null> $withheld chain number → the branch to keep
     * @return list<array<string, mixed>>
     */
    public static function prune(array $blocks, array $withheld): array
    {
        if ($withheld === []) {
            return $blocks;
        }

        $kept = [];
        foreach ($blocks as $block) {
            $pruned = self::pruned($block, $withheld);
            if ($pruned !== null) {
                $kept[] = $pruned;
            }
        }

        return $kept;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<int, int|null> $withheld
     * @return array<string, mixed>|null
     */
    private static function pruned(array $block, array $withheld): ?array
    {
        $marks = self::of($block);
        if ($marks !== null && array_key_exists($marks[0], $withheld)) {
            if ($withheld[$marks[0]] !== $marks[1]) {
                return null;
            }
            $attributes = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
            $block['attrs'] = [...$attributes, self::SHOWN_ATTRIBUTE => true];
        }
        if (($block['innerBlocks'] ?? []) === []) {
            return $block;
        }

        $content = [];
        $inner = [];
        $index = 0;
        $blocks = is_array($block['innerBlocks']) ? array_values($block['innerBlocks']) : [];
        foreach (is_array($block['innerContent'] ?? null) ? $block['innerContent'] : [] as $chunk) {
            if (is_string($chunk)) {
                $content[] = $chunk;
                continue;
            }
            $child = $blocks[$index++] ?? null;
            $child = is_array($child) ? self::pruned($child, $withheld) : null;
            if ($child !== null) {
                $content[] = null;
                $inner[] = $child;
            }
        }
        $block['innerBlocks'] = $inner;
        $block['innerContent'] = $content;

        return $block;
    }

    /**
     * The chain number and branch index a branch block was marked with; null for any other block.
     *
     * @param array<string, mixed> $block
     * @return array{int, int}|null
     */
    public static function of(array $block): ?array
    {
        $attributes = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        $chain = $attributes[self::CHAIN_ATTRIBUTE] ?? null;
        $branch = $attributes[self::BRANCH_ATTRIBUTE] ?? null;

        return is_int($chain) && is_int($branch) ? [$chain, $branch] : null;
    }

    /**
     * The page number a top-level page block was marked with; null for any other block.
     *
     * @param array<string, mixed> $block
     */
    public static function pageOf(array $block): ?int
    {
        $page = $block['attrs'][self::PAGE_ATTRIBUTE] ?? null;

        return is_int($page) ? $page : null;
    }

    /**
     * @param list<array<string, mixed>|string> $sequence
     * @return list<array<string, mixed>|string>
     */
    private function sequence(array $sequence): array
    {
        $marked = [];
        foreach (Chains::split($sequence) as $item) {
            if (!$item instanceof Chain) {
                $marked[] = is_array($item) ? $this->block($item) : $item;
                continue;
            }

            $number = $this->next++;
            $branches = [];
            foreach ($item->branches as $index => $branch) {
                $attributes = is_array($branch['attrs'] ?? null) ? $branch['attrs'] : [];
                $branch['attrs'] = [
                    ...$attributes,
                    self::CHAIN_ATTRIBUTE => $number,
                    self::BRANCH_ATTRIBUTE => $index,
                ];
                $branches[] = $branch;
            }
            // In document order: the chains inside each branch after this chain's own number.
            foreach ($branches as $branch) {
                $marked[] = $this->block($branch);
            }
        }

        return $marked;
    }

    /**
     * A block with its inner blocks marked: the markup around them stays, the placeholders follow the
     * blocks. The whitespace between a chain's branches is dropped, as it renders nothing.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function block(array $block): array
    {
        // The after-submit section's chains are not the form's: rendered by `Values::render()` alone.
        if (($block['innerBlocks'] ?? []) === [] || ($block['blockName'] ?? null) === self::AFTER_SUBMIT_BLOCK) {
            return $block;
        }

        $content = [];
        $inner = [];
        foreach ($this->sequence(Chains::sequenceOf($block)) as $item) {
            if (is_string($item)) {
                $content[] = $item;
            } else {
                $content[] = null;
                $inner[] = $item;
            }
        }
        $block['innerBlocks'] = $inner;
        $block['innerContent'] = $content;

        return $block;
    }
}
