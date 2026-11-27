<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * A field type whose field can hold an assignment set's requests (modules-and-extensions.md, "Assignment sets", request
 * mode), such as the module ranking: its block names the set, and its options are the set's (S44-1). The engine fills
 * the field's `options` attribute from the host (`FormHost::requestOptions()`), each `{value, label}`, with the
 * block's own `optionConditions` (option key → condition) as the options' conditions, so the field type reads its
 * options from its attributes as a choice does, and offers only those whose condition holds (S76). The field the
 * block declares becomes the set's one request field: a second, different field naming the same set is refused on
 * save.
 *
 * A block that names no set holds its own options instead (its type is then also an `OptionsFieldType`) and is no
 * request field: the block's set attribute decides the mode, block by block (the owner's ruling, 2026-11-18).
 *
 * The seam between a field type and the set it names.
 */
interface RequestFieldType extends FieldType
{
    /**
     * The ID of the set the block names; null when it names none and holds its own options. A block that names a
     * set that does not exist, or a number that is no set's ID (such as 0, "a set, none chosen yet"), is a problem
     * on save.
     *
     * @param array<string, mixed> $attributes the block's attributes as authored
     */
    public function requestSet(array $attributes): ?int;
}
