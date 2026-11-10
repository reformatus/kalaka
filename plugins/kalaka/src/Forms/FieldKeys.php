<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\FieldType\ChoiceFieldType;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * Sets the keys of a form's fields and the values of its choice options when the form is saved, by
 * any path (the block editor through REST, code, WP-CLI): events-and-forms.md, "Fields".
 *
 * A key or value, once set, stays: renaming the title or label changes nothing. One is derived
 * (`KeyDerivation`) only where it is missing, invalid, or repeats one earlier in the form (keys) or in
 * the field (values), in document order. Replacing an invalid or repeated one loses nothing: a form with
 * such a key or value is unusable (`DefinitionParser`), so nothing was ever stored under it.
 */
final class FieldKeys
{
    /**
     * The `wp_insert_post_data` filter: the content of a `kalaka_form` with every key and value set.
     *
     * @param array<string, mixed> $data the post's fields, slashed
     * @return array<string, mixed>
     */
    public static function onInsert(array $data): array
    {
        if (($data['post_type'] ?? null) !== FormPosts::POST_TYPE || !is_string($data['post_content'] ?? null)) {
            return $data;
        }
        $content = wp_unslash($data['post_content']);
        $derived = (new self())->derive($content, Contributions::current());
        if ($derived !== $content) {
            $data['post_content'] = wp_slash($derived);
        }

        return $data;
    }

    /** The block markup with every field key and option value set; unchanged when none was missing. */
    public function derive(string $content, Contributions $contributions): string
    {
        $fieldTypes = $contributions->fieldTypes();
        if ($fieldTypes === [] || !has_blocks($content)) {
            return $content;
        }

        $blocks = parse_blocks($content);
        $taken = [];
        $this->keep($blocks, $fieldTypes, $taken);
        $changed = false;
        $this->fill($blocks, $fieldTypes, $taken, $changed);

        /** @var array<array{blockName: string|null, attrs: array<string, mixed>, innerBlocks: array<array<string, mixed>>, innerHTML: string, innerContent: array<string|null>}> $blocks */
        return $changed ? serialize_blocks($blocks) : $content;
    }

    /**
     * First pass: every valid key not seen before in document order is kept; the others are cleared.
     *
     * @param array<array<string, mixed>> $blocks
     * @param array<string, mixed> $fieldTypes
     * @param array<string, true> $taken
     */
    private function keep(array &$blocks, array $fieldTypes, array &$taken): void
    {
        foreach ($blocks as &$block) {
            if (is_string($block['blockName'] ?? null) && isset($fieldTypes[$block['blockName']])) {
                $attributes = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                $key = $attributes['key'] ?? null;
                if (is_string($key) && preg_match(KeyDerivation::KEY_PATTERN, $key) === 1 && !isset($taken[$key])) {
                    $taken[$key] = true;
                } else {
                    unset($attributes['key']);
                    $block['attrs'] = $attributes;
                }
            }
            if (is_array($block['innerBlocks'] ?? null) && $block['innerBlocks'] !== []) {
                /** @var array<array<string, mixed>> $inner */
                $inner = &$block['innerBlocks'];
                $this->keep($inner, $fieldTypes, $taken);
                unset($inner);
            }
        }
        unset($block);
    }

    /**
     * Second pass: a key for every field without one, and a value for every option without one.
     *
     * @param array<array<string, mixed>> $blocks
     * @param array<string, mixed> $fieldTypes
     * @param array<string, true> $taken
     */
    private function fill(array &$blocks, array $fieldTypes, array &$taken, bool &$changed): void
    {
        foreach ($blocks as &$block) {
            $name = $block['blockName'] ?? null;
            if (is_string($name) && isset($fieldTypes[$name])) {
                /** @var array<string, mixed> $attributes */
                $attributes = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                if (!isset($attributes['key'])) {
                    $key = KeyDerivation::key(FieldMarkup::text($attributes, 'label'), $name, $taken);
                    $taken[$key] = true;
                    $attributes['key'] = $key;
                    $changed = true;
                }
                if ($name === ChoiceFieldType::BLOCK_NAME && is_array($attributes['options'] ?? null)) {
                    $attributes['options'] = $this->options($attributes['options'], $changed);
                }
                $block['attrs'] = $attributes;
            }
            if (is_array($block['innerBlocks'] ?? null) && $block['innerBlocks'] !== []) {
                /** @var array<array<string, mixed>> $inner */
                $inner = &$block['innerBlocks'];
                $this->fill($inner, $fieldTypes, $taken, $changed);
                unset($inner);
            }
        }
        unset($block);
    }

    /**
     * The options with every value set, the same way as keys: valid first occurrences kept, the rest
     * derived from the label. An option that is not an object is left for `DefinitionParser` to refuse.
     *
     * @param array<mixed> $options
     * @return array<mixed>
     */
    private function options(array $options, bool &$changed): array
    {
        $taken = [];
        $missing = [];
        foreach ($options as $index => $option) {
            if (!is_array($option)) {
                continue;
            }
            $value = $option['value'] ?? null;
            if (is_string($value) && preg_match(KeyDerivation::VALUE_PATTERN, $value) === 1 && !isset($taken[$value])) {
                $taken[$value] = true;
            } else {
                $missing[] = $index;
            }
        }
        foreach ($missing as $index) {
            /** @var array<string, mixed> $option */
            $option = $options[$index];
            $value = KeyDerivation::value(FieldMarkup::text($option, 'label'), $taken);
            $taken[$value] = true;
            $option['value'] = $value;
            $options[$index] = $option;
            $changed = true;
        }

        return $options;
    }
}
