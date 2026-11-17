<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * A field type whose field holds an assignment set's requests (modules-and-extensions.md, "Assignment sets", request
 * mode), such as the module ranking: its block names the set, and its options are the set's (S44-1). The engine fills
 * the field's `options` attribute from the host (`FormHost::requestOptions()`), each `{value, label}` and its
 * `condition` when it has one, so the field type reads its options from its attributes as a choice does, and offers
 * only those whose condition holds (S76). The field the block declares becomes the set's one request field: a second,
 * different field naming the same set is refused on save.
 *
 * [UNAPPROVED N4] the seam between a field type and the set it names.
 */
interface RequestFieldType extends FieldType
{
    /**
     * The ID of the set the block names; null when it names none (a problem on save).
     *
     * @param array<string, mixed> $attributes the block's attributes as authored
     */
    public function requestSet(array $attributes): ?int;
}
