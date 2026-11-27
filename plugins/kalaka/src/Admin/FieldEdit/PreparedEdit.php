<?php

declare(strict_types=1);

namespace Kalaka\Admin\FieldEdit;

use Kalaka\ParticipantFields\Api\ParticipantField;

/**
 * A staff edit's value turned into the field type's stored form, before the run (`FieldEditInput::prepare()`): a
 * field type runs outside any transaction. `stored` is null for a clear.
 */
final readonly class PreparedEdit
{
    public function __construct(
        public int $eventId,
        public ParticipantField $field,
        public ?string $stored,
    ) {
    }
}
