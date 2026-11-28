<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

/**
 * One word of the grid's search (S37). A row matches the word when some listed column shows it. Columns whose
 * shown text is not the stored value, the status, a choice, a yes/no and a decision's state, are matched by
 * the stored values whose shown word contains it (`keys`; an empty list matches nothing); a date column may
 * match another needle than the word (`likes`: the stored `Y-m-d` for a part of the shown `Y. m. d.`). Every
 * other listed column is matched as text, ignoring case and accents.
 */
final readonly class SearchWord
{
    /**
     * @param array<string, list<string>> $keys by column: the stored values whose shown word contains the word
     * @param array<string, string> $likes by column: the text to look for in the stored value instead of the word
     */
    public function __construct(public string $text, public array $keys = [], public array $likes = [])
    {
    }
}
