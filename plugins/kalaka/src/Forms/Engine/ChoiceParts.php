<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine;

use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormAvailability;
use Kalaka\Forms\Api\RequestFieldType;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Engine\FieldType\ChoiceFieldType;
use Kalaka\Forms\Engine\Value\InlineValues;
use Kalaka\Forms\Engine\Value\RenderStack;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * The choice fields (and request fields, S44) with option conditions, re-rendered with only the options they offer
 * for the answers (S76 seam: the re-fetch's `parts`). Each is the field block of the form's version as
 * `FormEngine::blocks()` gives it, rendered alone with `render_block()` and the host's values filled in, keyed by
 * field key. A field that does not show is rendered too, with no option (`[]`); the page disables its controls from
 * `fields`.
 */
final class ChoiceParts
{
    public function __construct(
        private readonly FormEngine $forms,
        private readonly InlineValues $inline = new InlineValues(),
    ) {
    }

    /**
     * Whether a block is a field whose options may have conditions: a choice field, or a request field
     * (`Api\RequestFieldType`, whose options are its set's, S44).
     */
    public static function offersOptions(mixed $blockName): bool
    {
        return $blockName === ChoiceFieldType::BLOCK_NAME
            || (is_string($blockName)
                && (Contributions::current()->fieldTypes()[$blockName] ?? null) instanceof RequestFieldType);
    }

    /**
     * @return array<string, string> field key → the field's markup
     * @throws \Kalaka\Foundation\Api\Error\SystemFailure
     */
    public function of(Form $form, int $version, FormAvailability $availability, ValueContext $values): array
    {
        $blocks = $this->choiceBlocks($this->forms->blocks($form, $version, $availability), $availability);
        if ($blocks === []) {
            return [];
        }

        $parts = [];
        RenderStack::push($values);
        try {
            foreach ($blocks as $key => $block) {
                $parts[$key] = $this->inline->fill(render_block($block), $values);
            }
        } finally {
            RenderStack::pop();
        }

        return $parts;
    }

    /**
     * The choice field blocks with option conditions, by key, wherever they sit, each with only its offered
     * options.
     *
     * @param array<array-key, mixed> $blocks
     * @return array<string, array<string, mixed>>
     */
    private function choiceBlocks(array $blocks, FormAvailability $availability): array
    {
        $found = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $key = $block['attrs']['key'] ?? null;
            $offered = is_string($key) ? $availability->offeredOptions($key) : null;
            if (self::offersOptions($block['blockName'] ?? null) && is_string($key) && $offered !== null) {
                $options = is_array($block['attrs']['options'] ?? null) ? $block['attrs']['options'] : [];
                $block['attrs']['options'] = FormPage::offeredOptions($options, $offered);
                /** @var array<string, mixed> $block */
                $found[$key] = $block;
                continue;
            }
            $inner = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
            $found += $this->choiceBlocks($inner, $availability);
        }

        return $found;
    }
}
