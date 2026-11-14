<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\Value;

use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\Engine\BlockGate;
use Kalaka\Forms\Engine\Condition\Chain;
use Kalaka\Forms\Engine\Condition\Chains;
use Kalaka\Forms\Engine\Condition\ConditionParser;
use Kalaka\Forms\Engine\Condition\DeclaredScope;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * Checks a document's block markup (a mail body) when it is saved, against the keys and types its context
 * will declare: every chain's placement and every condition in it, in every branch whether it would hold or
 * not, every `kalaka/value` binding and every inline value. What it accepts, `Renderer` renders for any
 * context with those keys. Its walk (`walk()`) is also `DefinitionParser`'s check of a form's after-submit
 * section, which refuses some blocks there and does not check values.
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
     *     `unknown_value_key <key>`, `field_in_document <block>`, `form_block_in_document <block>`
     */
    public function check(string $content, array $declared): void
    {
        $scope = new DeclaredScope($declared);
        $fieldTypes = Contributions::current()->fieldTypes();
        // A document never holds a field block or a form-only block (a page, the after-submit section), as a
        // form's after-submit section holds no field block (`field_in_after_submit`).
        $refuse = static function (array $block) use ($fieldTypes): void {
            $name = $block['blockName'] ?? null;
            if (is_string($name) && isset($fieldTypes[$name])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a block name, never output
                throw new SystemFailure('field_in_document ' . $name);
            }
            if (is_string($name) && in_array($name, BlockGate::FORM_ONLY_BLOCKS, true)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a block name, never output
                throw new SystemFailure('form_block_in_document ' . $name);
            }
        };
        $this->walk(array_values(parse_blocks($content)), $scope, true, $refuse);
        $this->inline($content, $scope);
    }

    /**
     * The save walk of parsed blocks: every chain's placement and every condition in every branch, every
     * block's inner blocks, and, when asked, every block's `kalaka/value` bindings.
     *
     * @param list<array<string, mixed>|string> $sequence
     * @param (\Closure(array<string, mixed>): void)|null $visit called with every block first; it throws to
     *     refuse one
     * @throws SystemFailure
     */
    public function walk(array $sequence, DeclaredScope $scope, bool $bindings, ?\Closure $visit = null): void
    {
        foreach (Chains::split($sequence) as $item) {
            $blocks = $item instanceof Chain ? $item->branches : (is_array($item) ? [$item] : []);
            foreach ($blocks as $block) {
                if ($visit !== null) {
                    $visit($block);
                }
                $attributes = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                if ($item instanceof Chain && ($block['blockName'] ?? null) !== Chains::ELSE_BLOCK) {
                    $this->parser->parse($attributes['condition'] ?? null, $scope);
                }
                if ($bindings) {
                    $this->bindings($attributes, $scope);
                }
                $this->walk(Chains::sequenceOf($block), $scope, $bindings, $visit);
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
