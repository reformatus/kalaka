<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Engine\FieldMarkup;
use Kalaka\Forms\Engine\FormPosts;
use Kalaka\Forms\Engine\FieldType\ChoiceFieldType;
use Kalaka\Forms\ParticipantField\FieldBlocks;
use Kalaka\Forms\ParticipantField\SharedKeyCheck;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * Sets the keys of a form's fields and the values of its choice options when the form is saved, by
 * any path (the block editor through REST, code, WP-CLI): events-and-forms.md, "Fields".
 *
 * A key or value, once set, stays: renaming the title or label changes nothing. A key is derived
 * (`KeyDerivation`) only where it is missing or invalid; a value where it is missing, invalid or repeats one
 * earlier in the field. Replacing an invalid one loses nothing: a form with such a key or value is unusable
 * (`DefinitionParser`), so nothing was ever stored under it. A key that repeats one earlier in the same form
 * is kept, never derived again: which of the two blocks the stored answers belong to cannot be told, so the
 * save check refuses the form (`duplicate_key`) instead.
 *
 * A derived key is unique across the form's event: it takes none of the event's participant-field keys,
 * retired ones included, so a key is never reused (owner ruling 2026-11-11). A key the form already holds
 * is kept even when another form of the event holds it too: that is the same participant field, on purpose.
 * A form without an event derives within the form only.
 */
final class FieldKeys
{
    /** @var array<string, array{string, string}> the stored fields no incoming block holds: key → type, title */
    private array $own = [];

    /**
     * The `wp_insert_post_data` filter: the content of a `kalaka_form` with every key and value set.
     *
     * @param array<string, mixed> $data the post's fields, slashed
     * @param array<string, mixed> $postarr
     * @return array<string, mixed>
     */
    public static function onInsert(array $data, array $postarr = []): array
    {
        if (($data['post_type'] ?? null) !== FormPosts::POST_TYPE || !is_string($data['post_content'] ?? null)) {
            return $data;
        }
        $id = is_int($postarr['ID'] ?? null) ? $postarr['ID'] : 0;
        $content = wp_unslash($data['post_content']);
        [$eventId] = FormEvent::resolve($id, FormEvent::requested($id, $postarr), $content);
        $derived = (new self())->derive(
            $content,
            Contributions::current(),
            SharedKeyCheck::takenKeys($eventId),
            self::stored($id),
        );
        if ($derived !== $content) {
            $data['post_content'] = wp_slash($derived);
        }

        return $data;
    }

    /** The stored content of a form, the save's previous state; empty for a new one. */
    public static function stored(int $id): string
    {
        $post = $id > 0 ? get_post($id) : null;

        return $post instanceof \WP_Post && $post->post_type === FormPosts::POST_TYPE ? $post->post_content : '';
    }

    /**
     * The block markup with every field key and option value set; unchanged when none was missing.
     *
     * A field block that arrives without a key first takes back its own key from the form's stored content:
     * the first stored field of the same type and the same title whose key no block of the incoming content
     * holds. The block editor keeps its own block state and does not re-read what the server set, so without
     * this a keyless block would derive again on every save, against the event's keys, which by then include
     * the key it got on the save before (`x`, then `x_1`). Matching by type and title, not by position, keeps
     * the key when a block is inserted or moved before it.
     *
     * @param array<string, true> $eventKeys the keys a derived key must not take: the event's participant fields
     * @param string $previous the form's stored content
     */
    public function derive(
        string $content,
        Contributions $contributions,
        array $eventKeys = [],
        string $previous = '',
    ): string {
        $fieldTypes = $contributions->fieldTypes();
        if ($fieldTypes === [] || !has_blocks($content)) {
            return $content;
        }

        $blocks = parse_blocks($content);
        $taken = [];
        $this->keep($blocks, $fieldTypes, $taken);
        $this->own = [];
        foreach ($previous === '' ? [] : FieldBlocks::of($previous, $contributions) as $key => $field) {
            if (!isset($taken[$key])) {
                $this->own[$key] = [$field['type'], $field['title']];
                $taken[$key] = true;
            }
        }
        $taken += $eventKeys;
        $changed = false;
        $this->fill($blocks, $fieldTypes, $taken, $changed);

        /** @var array<array{blockName: string|null, attrs: array<string, mixed>, innerBlocks: array<array<string, mixed>>, innerHTML: string, innerContent: array<string|null>}> $blocks */
        return $changed ? serialize_blocks($blocks) : $content;
    }

    /**
     * First pass: every valid key is kept, a repeated one too (the save check refuses it); the others are
     * cleared.
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
                if (is_string($key) && preg_match(KeyDerivation::KEY_PATTERN, $key) === 1) {
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
                    $title = FieldMarkup::text($attributes, 'label');
                    $key = $this->ownKey($name, $title) ?? KeyDerivation::key($title, $name, $taken);
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

    /** The stored key of the first unclaimed stored field of this type and title, claimed now; or null. */
    private function ownKey(string $type, string $title): ?string
    {
        foreach ($this->own as $key => [$ownType, $ownTitle]) {
            if ($ownType === $type && $ownTitle === $title) {
                unset($this->own[$key]);

                return $key;
            }
        }

        return null;
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
