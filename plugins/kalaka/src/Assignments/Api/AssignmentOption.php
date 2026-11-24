<?php

declare(strict_types=1);

namespace Kalaka\Assignments\Api;

/**
 * One option of an assignment set (modules-and-extensions.md, "Assignment sets"): IVK's module. Its key is
 * derived once from the label (`Forms\Api\KeyDerivation::value()`) and never changes. Who may pick it is
 * decided in the form (the form field that names the set may hold a condition per option), not here. The
 * limit applies only this option, and only at confirmation (request mode); `leaders` and `place` are display text.
 */
final readonly class AssignmentOption
{
    public function __construct(
        public int $setId,
        public string $key,
        public string $label,
        public ?int $limit,
        public string $leaders,
        public string $place,
        public int $position,
    ) {
    }
}
