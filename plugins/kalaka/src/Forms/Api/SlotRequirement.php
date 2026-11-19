<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * An extension's requirement on an event's registration form, contributed through
 * `Contributions::slotRequirement()`: the keys of participant fields the form must hold, required and outside
 * any condition branch or conditional page. A registration form of the event that lacks one is closed until
 * it is fixed (`required_field_missing`, named in the broken-form warning), and saving it as published is
 * refused, like a registration form without its contact email. Read outside any use case, from the event's
 * current state. [UNAPPROVED, #200 N16]
 */
interface SlotRequirement
{
    /** @return list<string> the participant field keys the event's registration form must hold */
    public function requiredKeys(int $eventId): array;
}
