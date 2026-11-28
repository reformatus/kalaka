<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

use Kalaka\Foundation\Api\Decision\CurrentDecision;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * One registration as the views that hold it show it: only what their columns list, or everything when
 * one of them is the master view (`$full`). Nothing the views do not list is read into it, except
 * `$slotForm`, which only locates the contact fields and is never shown.
 */
final readonly class ViewRegistration
{
    /**
     * @param ?RegistrationStatus $status null unless a view lists `status`, or full
     * @param ?int $serial null unless a view lists `serial`, or full; also null where the registration has none
     * @param array<string, ?string> $fields the stored values of the listed field keys (all, when full), by key;
     *     a field without an answer is left out
     * @param array<string, CurrentDecision> $decisions the current decisions of the listed kinds (all, when
     *     full), by kind
     * @param ?array{form_id: int, form_version: int} $slotForm the form and version of the first submission
     * @param list<string> $columns the union of the views' columns; empty when full
     */
    public function __construct(
        public RegistrationKey $key,
        public bool $full,
        public array $columns,
        public ?RegistrationStatus $status,
        public ?int $serial,
        public ?\DateTimeImmutable $registeredAt,
        public ?\DateTimeImmutable $submittedAt,
        public array $fields,
        public array $decisions,
        public ?array $slotForm,
    ) {
    }

    /** Whether the column is shown: listed by a held view, or everything when full. */
    public function lists(string $column): bool
    {
        return $this->full || in_array($column, $this->columns, true);
    }
}
