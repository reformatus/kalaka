<?php

declare(strict_types=1);

namespace Kalaka\Forms\Condition;

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

    private int $next = 0;

    /**
     * @param list<array<string, mixed>> $blocks parsed blocks of a whole document (`parse_blocks()`)
     * @return list<array<string, mixed>> the same blocks, every branch block marked
     * @throws SystemFailure a branch that does not follow an `if` or `else-if` (`orphan_branch`)
     */
    public static function mark(array $blocks): array
    {
        $marks = new self();
        $marked = [];
        foreach ($marks->sequence($blocks) as $item) {
            if (is_array($item)) {
                $marked[] = $item;
            }
        }

        return $marked;
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
        if (($block['innerBlocks'] ?? []) === []) {
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
