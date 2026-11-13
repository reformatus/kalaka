<?php

declare(strict_types=1);

namespace Kalaka\Forms\Value;

use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Condition\Chain;
use Kalaka\Forms\Condition\Chains;
use Kalaka\Forms\Condition\ConditionEvaluator;
use Kalaka\Forms\Condition\ConditionParser;
use Kalaka\Forms\Condition\ContextScope;
use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * Resolves every chain of parsed blocks against a context before anything renders: of each chain
 * only the held branch's block stays (its inner blocks resolved in turn), so the blocks that
 * render are exactly the ones that show. A chain in a branch that does not hold is never looked at.
 * On a page, a chain that fails (a misplaced branch, an invalid condition, a key the context does not
 * declare) renders nothing and is logged; the rest of the page renders.
 */
final class ChainPass
{
    public function __construct(
        private readonly ConditionParser $parser = new ConditionParser(),
        private readonly ConditionEvaluator $evaluator = new ConditionEvaluator(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $blocks parsed blocks
     * @return list<array<string, mixed>>
     * @throws SystemFailure a misplaced branch, a condition that is not valid or reads an undeclared key,
     *     outside a page
     */
    public function resolve(array $blocks, ValueContext $context, bool $page = false): array
    {
        $resolved = [];
        foreach ($this->sequence($blocks, $context, $page) as $item) {
            if (is_array($item)) {
                $resolved[] = $item;
            }
        }

        return $resolved;
    }

    /**
     * @param list<array<string, mixed>|string> $sequence
     * @return list<array<string, mixed>|string>
     */
    private function sequence(array $sequence, ValueContext $context, bool $page): array
    {
        $items = Chains::split($sequence, $page ? RenderStack::reportOnPage(...) : null);

        $resolved = [];
        foreach ($items as $item) {
            if ($item instanceof Chain) {
                $held = $this->heldOnPage($item, $context, $page);
                if ($held !== null) {
                    $attributes = is_array($held['attrs'] ?? null) ? $held['attrs'] : [];
                    $held['attrs'] = [...$attributes, RenderStack::HELD_ATTRIBUTE => true];
                    $resolved[] = $this->block($held, $context, $page);
                }
            } elseif (is_array($item)) {
                $resolved[] = $this->block($item, $context, $page);
            } else {
                $resolved[] = $item;
            }
        }

        return $resolved;
    }

    /**
     * A block with its inner blocks resolved: the markup around them stays, the placeholders follow
     * the blocks that are left.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function block(array $block, ValueContext $context, bool $page): array
    {
        if (($block['innerBlocks'] ?? []) === []) {
            return $block;
        }

        $content = [];
        $inner = [];
        foreach ($this->sequence(Chains::sequenceOf($block), $context, $page) as $item) {
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

    /**
     * The held branch; on a page, none when the chain fails (logged).
     *
     * @return array<string, mixed>|null
     * @throws SystemFailure outside a page
     */
    private function heldOnPage(Chain $chain, ValueContext $context, bool $page): ?array
    {
        try {
            return $this->held($chain, $context);
        } catch (SystemFailure $e) {
            if (!$page) {
                throw $e;
            }
            RenderStack::reportOnPage($e);

            return null;
        }
    }

    /**
     * The first branch whose condition holds; `kalaka/else` holds when none before it does. Every
     * condition of the chain is read first, so a problem shows whichever branch holds.
     *
     * @return array<string, mixed>|null
     */
    private function held(Chain $chain, ValueContext $context): ?array
    {
        $scope = new ContextScope($context);
        $conditions = [];
        foreach ($chain->branches as $branch) {
            $attributes = is_array($branch['attrs'] ?? null) ? $branch['attrs'] : [];
            $conditions[] = ($branch['blockName'] ?? null) === Chains::ELSE_BLOCK
                ? null
                : $this->parser->parse($attributes['condition'] ?? null, $scope);
        }

        foreach ($chain->branches as $index => $branch) {
            $condition = $conditions[$index];
            if ($condition === null || $this->evaluator->holds($condition, $context)) {
                return $branch;
            }
        }

        return null;
    }
}
