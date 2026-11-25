<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * One test of one participant field's stored value, a part of an `AwaitingRule`: the field key, the kind of test
 * and, for `OneOf` and `NoneOf`, the values (at least one; `Filled` and `Empty` take none). Values are compared as
 * stored, text for text. [UNAPPROVED N669]
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
