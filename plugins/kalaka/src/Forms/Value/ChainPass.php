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
     * @throws SystemFailure a misplaced branch, a condition that is not valid or reads an undeclared key
     */
    public function resolve(array $blocks, ValueContext $context): array
    {
        $resolved = [];
        foreach ($this->sequence($blocks, $context) as $item) {
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
    private function sequence(array $sequence, ValueContext $context): array
    {
        $resolved = [];
        foreach (Chains::split($sequence) as $item) {
            if ($item instanceof Chain) {
                $held = $this->held($item, $context);
                if ($held !== null) {
                    $attributes = is_array($held['attrs'] ?? null) ? $held['attrs'] : [];
                    $held['attrs'] = [...$attributes, RenderStack::HELD_ATTRIBUTE => true];
                    $resolved[] = $this->block($held, $context);
                }
            } elseif (is_array($item)) {
                $resolved[] = $this->block($item, $context);
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
    private function block(array $block, ValueContext $context): array
    {
        if (($block['innerBlocks'] ?? []) === []) {
            return $block;
        }

        $content = [];
        $inner = [];
        foreach ($this->sequence(Chains::sequenceOf($block), $context) as $item) {
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
