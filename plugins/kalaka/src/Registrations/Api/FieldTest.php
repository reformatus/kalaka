<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * One test of one participant field's stored value, a part of an `AwaitingRule`: the field key, the kind of test
 * and, for `OneOf` and `NoneOf`, the values (at least one, none of them empty, for the empty string is what an
 * absent value reads as and `Empty` already says it; `Filled` and `Empty` take none). Values are compared exactly
 * as stored, byte for byte, in PHP and in the SQL core compiles.
 */
final readonly class FieldTest
{
    /** @param list<string> $values */
    public function __construct(public string $key, public FieldTestKind $kind, public array $values = [])
    {
        $takesValues = $kind === FieldTestKind::OneOf || $kind === FieldTestKind::NoneOf;
        if ($key === '' || $takesValues === ($values === [])) {
            throw new \LogicException('A field test needs a key, and values exactly for "one of" and "none of".');
        }
        if (in_array('', $values, true)) {
            throw new \InvalidArgumentException('A field test\'s values must not be empty; use "empty" or "filled".');
        }
    }

    /** @param array<string, string> $values the registration's current field values, key → stored value */
    public function matches(array $values): bool
    {
        $value = $values[$this->key] ?? '';

        return match ($this->kind) {
            FieldTestKind::Filled => $value !== '',
            FieldTestKind::Empty => $value === '',
            FieldTestKind::OneOf => in_array($value, $this->values, true),
            FieldTestKind::NoneOf => !in_array($value, $this->values, true),
        };
    }
}
