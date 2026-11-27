<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Naming;

use Kalaka\Foundation\Api\Error\InvalidInput;

/**
 * The names a plugin declares for its own fields and labels, and what its settings screen has changed them to.
 * A plugin declares each `DeclaredName` (a key and a default) and reads the text through `get()`. A value is kept
 * only when it holds something: an empty or whitespace-only one falls back to the declared default, as does a
 * stored value of the wrong shape or too long. The read never throws on what is stored. The value is read where it
 * is shown, so a change renames what already exists.
 */
final class PluginNames
{
    /** The longest a name may be, in characters. */
    public const MAX_LENGTH = 120;

    /** @var array<string, DeclaredName> */
    private readonly array $declared;

    /** @param list<DeclaredName> $names */
    public function __construct(
        private readonly NameStore $store,
        array $names,
    ) {
        $declared = [];
        foreach ($names as $name) {
            if (isset($declared[$name->key])) {
                throw new \InvalidArgumentException('A name is declared once.');
            }
            $declared[$name->key] = $name;
        }
        $this->declared = $declared;
    }

    /** @return list<DeclaredName> */
    public function declared(): array
    {
        return array_values($this->declared);
    }

    /** The text of a declared name: the stored one, or the declared default. */
    public function get(string $key): string
    {
        if (!isset($this->declared[$key])) {
            throw new \LogicException('The name is not declared.');
        }

        return $this->overrides()[$key] ?? $this->declared[$key]->default;
    }

    /** @return array<string, string> the usable stored values by declared key */
    public function overrides(): array
    {
        $stored = $this->store->read();
        if (!is_array($stored)) {
            return [];
        }
        $usable = [];
        foreach (array_keys($this->declared) as $key) {
            $value = $stored[$key] ?? null;
            $value = is_string($value) ? self::trimmed($value) : '';
            if ($value !== '' && mb_strlen($value) <= self::MAX_LENGTH) {
                $usable[$key] = $value;
            }
        }

        return $usable;
    }

    /**
     * What a settings screen sent, as it is kept: the declared keys only, each trimmed; an empty one is dropped, so
     * it falls back to the default.
     *
     * @return array<string, string>
     * @throws InvalidInput an undeclared key, a value that is not text, or one longer than `MAX_LENGTH`
     */
    public function checked(mixed $input): array
    {
        if (!is_array($input)) {
            throw new InvalidInput();
        }
        $kept = [];
        foreach ($input as $key => $value) {
            if (!is_string($key) || !isset($this->declared[$key]) || !is_string($value)) {
                throw new InvalidInput();
            }
            $value = self::trimmed($value);
            if (mb_strlen($value) > self::MAX_LENGTH) {
                throw new InvalidInput();
            }
            if ($value !== '') {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }

    private static function trimmed(string $value): string
    {
        return preg_replace('/\A\s+|\s+\z/u', '', $value) ?? '';
    }
}
