<?php

declare(strict_types=1);

namespace Kalaka\Forms\ParticipantField;

use Kalaka\Forms\Engine\FieldMarkup;
use Kalaka\Forms\Engine\FieldType\ChoiceFieldType;
use Kalaka\Forms\KeyDerivation;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * The participant fields a form's content holds: every field block with a valid key, in document order,
 * whatever core blocks it sits in. Lenient where `DefinitionParser` is strict, because a draft can be half
 * built: a block without a valid key holds nothing yet, a repeated key counts once (the first), and an
 * option without a valid value is left out.
 */
final class FieldBlocks
{
    /**
     * @return array<string, array{type: string, title: string, options: ?list<array{value: string, label: string}>}>
     *     by field key
     */
    public static function of(string $content, Contributions $contributions): array
    {
        $fieldTypes = $contributions->fieldTypes();
        if ($fieldTypes === [] || !has_blocks($content)) {
            return [];
        }
        $fields = [];
        self::collect(parse_blocks($content), $fieldTypes, $fields);

        return $fields;
    }

    /** Whether the content holds any field block, with a key or without. */
    public static function any(string $content, Contributions $contributions): bool
    {
        $fieldTypes = $contributions->fieldTypes();

        return $fieldTypes !== [] && has_blocks($content)
            && self::hasField(parse_blocks($content), $fieldTypes);
    }

    /** The first valid key that two field blocks of the content hold, in document order, or null. */
    public static function repeatedKey(string $content, Contributions $contributions): ?string
    {
        $fieldTypes = $contributions->fieldTypes();
        if ($fieldTypes === [] || !has_blocks($content)) {
            return null;
        }
        $keys = [];
        self::keys(parse_blocks($content), $fieldTypes, $keys);
        $seen = [];
        foreach ($keys as $key) {
            if (isset($seen[$key])) {
                return $key;
            }
            $seen[$key] = true;
        }

        return null;
    }

    /**
     * @param array<array<string, mixed>> $blocks
     * @param array<string, mixed> $fieldTypes
     * @param list<string> $keys
     */
    private static function keys(array $blocks, array $fieldTypes, array &$keys): void
    {
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? null;
            $attributes = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
            $key = $attributes['key'] ?? null;
            if (
                is_string($name) && isset($fieldTypes[$name])
                && is_string($key) && preg_match(KeyDerivation::KEY_PATTERN, $key) === 1
            ) {
                $keys[] = $key;
            }
            if (is_array($block['innerBlocks'] ?? null) && $block['innerBlocks'] !== []) {
                /** @var array<array<string, mixed>> $inner */
                $inner = $block['innerBlocks'];
                self::keys($inner, $fieldTypes, $keys);
            }
        }
    }

    /**
     * @param array<array<string, mixed>> $blocks
     * @param array<string, mixed> $fieldTypes
     * @param array<string, array{
     *     type: string,
     *     title: string,
     *     options: ?list<array{value: string, label: string}>,
     * }> $fields
     */
    private static function collect(array $blocks, array $fieldTypes, array &$fields): void
    {
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? null;
            if (is_string($name) && isset($fieldTypes[$name])) {
                /** @var array<string, mixed> $attributes */
                $attributes = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                $key = $attributes['key'] ?? null;
                if (is_string($key) && preg_match(KeyDerivation::KEY_PATTERN, $key) === 1 && !isset($fields[$key])) {
                    $fields[$key] = [
                        'type' => $name,
                        'title' => FieldMarkup::text($attributes, 'label'),
                        'options' => $name === ChoiceFieldType::BLOCK_NAME ? self::options($attributes) : null,
                    ];
                }
            }
            if (is_array($block['innerBlocks'] ?? null) && $block['innerBlocks'] !== []) {
                /** @var array<array<string, mixed>> $inner */
                $inner = $block['innerBlocks'];
                self::collect($inner, $fieldTypes, $fields);
            }
        }
    }

    /**
     * @param array<array<string, mixed>> $blocks
     * @param array<string, mixed> $fieldTypes
     */
    private static function hasField(array $blocks, array $fieldTypes): bool
    {
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? null;
            if (is_string($name) && isset($fieldTypes[$name])) {
                return true;
            }
            if (is_array($block['innerBlocks'] ?? null) && $block['innerBlocks'] !== []) {
                /** @var array<array<string, mixed>> $inner */
                $inner = $block['innerBlocks'];
                if (self::hasField($inner, $fieldTypes)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return list<array{value: string, label: string}>
     */
    private static function options(array $attributes): array
    {
        $options = [];
        $seen = [];
        foreach (is_array($attributes['options'] ?? null) ? $attributes['options'] : [] as $option) {
            if (!is_array($option)) {
                continue;
            }
            $value = $option['value'] ?? null;
            if (is_string($value) && preg_match(KeyDerivation::VALUE_PATTERN, $value) === 1 && !isset($seen[$value])) {
                $seen[$value] = true;
                /** @var array<string, mixed> $option */
                $options[] = ['value' => $value, 'label' => FieldMarkup::text($option, 'label')];
            }
        }

        return $options;
    }
}
