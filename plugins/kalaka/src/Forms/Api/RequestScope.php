<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * What a condition of a set's option may read where a live form holds the set's request field
 * (`Forms::requestScopes()`): the fields before that request field, in document order, and the host's keys.
 * Read from the form before a use case's transaction, and judged inside it by `Availability::checkInForm()`,
 * so a set option's condition is refused when it would break a live form (S44).
 */
final readonly class RequestScope
{
    /**
     * @param list<FormField> $earlier the fields before the request field
     * @param array<string, ValueType> $declared the host's keys (`FormHost::factTypes()`)
     */
    public function __construct(
        public int $formId,
        public int $setId,
        public array $earlier,
        public array $declared,
    ) {
    }
}
