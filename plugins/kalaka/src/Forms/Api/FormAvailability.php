<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * What a form shows for its current answers (S76): the read model the form's page renders from, at
 * the first render and at every re-render (S12). The page renders only what shows; its script holds
 * no condition, it only re-fetches when a trigger's answer changes.
 *
 * Mid-form availability is advisory: a seat or a place is decided only at submit, under the lock.
 *
 * Decision N1 (#156): the read model and its array shape (`toArray()`), the seam of the form front end.
 */
final readonly class FormAvailability
{
    /**
     * @param array<string, bool> $fields field key → whether it shows, every field in document order
     * @param list<int|null> $chains chain number → the index of its branch that shows; null only when a branch
     *     around the chain does not hold, or none of its branches holds and it has no `else` (never "pending")
     * @param array<string, list<string>> $options choice field with option conditions → its offered option values
     * @param list<string> $triggers the field keys any condition reads, in document order
     * @param list<int> $withheld the chains whose branches the page gets only when they show (N11): when
     *     the held branch of one changes, the re-fetch sends that chain's markup
     */
    public function __construct(
        public array $fields,
        public array $chains,
        public array $options,
        public array $triggers,
        public array $withheld = [],
    ) {
    }

    public function shows(string $fieldKey): bool
    {
        return $this->fields[$fieldKey] ?? false;
    }

    /** The index of the chain's branch that shows; null when none does or the chain is inside one that does not. */
    public function heldBranch(int $chain): ?int
    {
        return $this->chains[$chain] ?? null;
    }

    /**
     * The option values a choice field offers, in authored order; null when no option of the field has
     * a condition (every option is offered).
     *
     * @return list<string>|null
     */
    public function offeredOptions(string $fieldKey): ?array
    {
        return $this->options[$fieldKey] ?? null;
    }

    /**
     * The shape the front end consumes, JSON-ready:
     * `{"fields": {key: bool}, "chains": [index|null, …], "options": {key: [value, …]}, "triggers": [key, …],
     * "withheld": [chain, …]}`.
     *
     * @return array{
     *     fields: array<string, bool>,
     *     chains: list<int|null>,
     *     options: array<string, list<string>>,
     *     triggers: list<string>,
     *     withheld: list<int>
     * }
     */
    public function toArray(): array
    {
        return [
            'fields' => $this->fields,
            'chains' => $this->chains,
            'options' => $this->options,
            'triggers' => $this->triggers,
            'withheld' => $this->withheld,
        ];
    }
}
