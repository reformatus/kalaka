<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * The fields of one version of a form, in document order, with its chains in order of their numbers
 * and the triggers: the field keys any condition of the form reads, in document order. A change of
 * a trigger's answer can change what the form shows (S12 re-renders on it).
 */
final readonly class FormDefinition
{
    /**
     * @param list<FormField> $fields
     * @param list<FormChain> $chains
     * @param list<string> $triggers
     */
    public function __construct(
        public int $formId,
        public int $version,
        public array $fields,
        public array $chains = [],
        public array $triggers = [],
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
