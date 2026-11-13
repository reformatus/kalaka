<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/** The fields of one version of a form, in document order. */
final readonly class FormDefinition
{
    /** @param list<FormField> $fields */
    public function __construct(
        public int $formId,
        public int $version,
        public array $fields,
    ) {
    }

    public function field(string $key): ?FormField
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }
}
