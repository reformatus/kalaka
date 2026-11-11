<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * Something whose availability one condition decides (S76): a price-ladder tier, an offer, the
 * adjudicated aid, an option of an assignment set (a form's own pages go through `FormWalk`). The
 * module that owns it stores its condition in the condition format (events-and-forms.md, "Conditions") and hands it to
 * `Availability::available()`; it never evaluates a condition itself.
 *
 * Decision N1 (#156): the interface the evaluator consumes for the things Forms does not own.
 */
interface Conditional
{
    /** The owner's stable key of the thing, unique among those passed together. */
    public function availabilityKey(): string;

    /**
     * The condition as stored, or null when the thing is always available.
     *
     * @return array<array-key, mixed>|null
     */
    public function availabilityCondition(): ?array;
}
