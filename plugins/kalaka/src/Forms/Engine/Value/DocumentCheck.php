<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\Value;

use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\Engine\Condition\Chain;
use Kalaka\Forms\Engine\Condition\Chains;
use Kalaka\Forms\Engine\Condition\ConditionParser;
use Kalaka\Forms\Engine\Condition\DeclaredScope;
use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * Checks a document's block markup (a mail body) when it is saved, against the keys and types its context
 * will declare: every chain's placement and every condition in it, in every branch whether it would hold or
 * not, every `kalaka/value` binding and every inline value. What it accepts, `Renderer` renders for any
 * context with those keys; it is the check `DefinitionParser` makes of a form's after-submit section, for
 * values too.
 */
final class DocumentCheck
{
    private const ATTRIBUTE = 'data-kalaka-value';

    public function __construct(private readonly ConditionParser $parser = new ConditionParser())
    {
    }

    /**
     * @param array<string, ValueType> $declared
     * @throws SystemFailure the first problem, `<code> <subject>`: `orphan_branch`, `invalid_condition <key>`,
     *     `unknown_value_key <key>`
     */
    public function check(string $content, array $declared): void
    {
        $scope = new DeclaredScope($declared);
        $this->sequence(array_values(parse_blocks($content)), $scope);
        $this->inline($content, $scope);
    }

    /** @param list<array<string, mixed>|string> $sequence */
    private function sequence(array $sequence, DeclaredScope $scope): void
    {
        foreach (Chains::split($sequence) as $item) {
            $blocks = $item instanceof Chain ? $item->branches : (is_array($item) ? [$item] : []);
            foreach ($blocks as $block) {
                $attributes = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                if ($item instanceof Chain && ($block['blockName'] ?? null) !== Chains::ELSE_BLOCK) {
                    $this->parser->parse($attributes['condition'] ?? null, $scope);
                }
                $this->bindings($attributes, $scope);
                $this->sequence(Chains::sequenceOf($block), $scope);
            }
        }
    }

    /** @param array<string, mixed> $attributes */
    private function bindings(array $attributes, DeclaredScope $scope): void
    {
        $metadata = is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [];
        $bindings = is_array($metadata['bindings'] ?? null) ? $metadata['bindings'] : [];
        foreach ($bindings as $binding) {
            if (is_array($binding) && ($binding['source'] ?? null) === BindingSource::NAME) {
                $args = is_array($binding['args'] ?? null) ? $binding['args'] : [];
                $key = $args['key'] ?? null;
                $scope->typeOf(is_string($key) ? $key : '');
            }
        }
    }

    private function inline(string $content, DeclaredScope $scope): void
    {
        $processor = new \WP_HTML_Tag_Processor($content);
        while ($processor->next_tag(['tag_name' => 'SPAN'])) {
            $key = $processor->get_attribute(self::ATTRIBUTE);
            if ($key !== null) {
                $scope->typeOf(is_string($key) ? $key : '');
            }
        }
    }
}
