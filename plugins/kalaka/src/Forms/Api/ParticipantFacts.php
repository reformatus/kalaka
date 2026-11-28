<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * The typed values of a registration's stored answers with each field's kind, both by participant-field key
 * (`Forms::participantFacts()`): one entry for every field of the event, retired ones included.
 */
final readonly class ParticipantFacts
{
    /**
     * @param array<string, Value> $values
     * @param array<string, FieldKind> $kinds
     */
    public function __construct(public array $values, public array $kinds)
    {
    }
}
