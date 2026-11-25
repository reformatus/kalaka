<?php

declare(strict_types=1);

namespace Kalaka\Assignments\Api;

use Kalaka\Assignments\AssignmentRepository;
use Kalaka\Assignments\OptionRecord;
use Kalaka\Assignments\OptionRepository;
use Kalaka\Assignments\PlacementWarnings;
use Kalaka\Assignments\SaveAssignmentOption;
use Kalaka\Assignments\SaveAssignmentSet;
use Kalaka\Assignments\SetRecord;
use Kalaka\Assignments\SetRepository;
use Kalaka\Assignments\TieRequestField;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * What other modules and extensions may do with assignment sets (modules-and-extensions.md, "Assignment
 * sets"): read a set with its options, read a registration's assignment, and write through the module's
 * use cases. The reads are plain reads, outside or inside a transaction; the writes run a use case each.
 */
final class AssignmentSets
{
    public function __construct(
        private readonly ?Runner $runner = null,
        private readonly SetRepository $sets = new SetRepository(),
        private readonly OptionRepository $options = new OptionRepository(),
        private readonly AssignmentRepository $assignments = new AssignmentRepository(),
    ) {
    }

    /** The set with its options; null when there is no such set. */
    public function find(int $setId): ?AssignmentSet
    {
        $set = $this->sets->find($setId);

        return $set === null ? null : self::toSet($set, $this->options->ofEvent($set->eventId));
    }

    /**
     * The event's sets by ID, each with its options, in two statements.
     *
     * @return list<AssignmentSet>
     */
    public function ofEvent(int $eventId): array
    {
        $options = $this->options->ofEvent($eventId);

        return array_map(
            static fn (SetRecord $set): AssignmentSet => self::toSet($set, $options),
            $this->sets->ofEvent($eventId)
        );
    }

    /** The registration's assignment in the set; null when it holds none. */
    public function assignment(RegistrationKey $registration, int $setId): ?Assignment
    {
        return $this->assignments->find(['registration' => $registration, 'setId' => $setId])?->toAssignment();
    }

    /**
     * What staff are told before placing the registration into the option by hand: the option is full
     * (`Assignments\PlacementWarnings`). Placing never refuses for it; a plain read for a screen or the command
     * to ask first.
     *
     * @return list<string> the warnings' texts, none when there is nothing to warn about
     */
    public function placementWarnings(RegistrationKey $registration, int $setId, string $optionKey): array
    {
        return (new PlacementWarnings())->of($registration, $setId, $optionKey);
    }

    /**
     * Creates or updates a set (`kalaka/save-assignment-set`); the input is passed on as given.
     *
     * @param array<string, mixed> $input
     * @return int the set's ID
     * @throws \Kalaka\Foundation\Api\Error\KalakaException anything the run throws
     */
    public function save(array $input, Actor $actor): int
    {
        return (int) $this->runner()->run(new SaveAssignmentSet(), $input, $actor);
    }

    /**
     * Adds or updates an option of a set (`kalaka/save-assignment-option`); the input is passed on as given.
     *
     * @param array<string, mixed> $input
     * @return string the option's key
     * @throws \Kalaka\Foundation\Api\Error\KalakaException anything the run throws
     */
    public function saveOption(array $input, Actor $actor): string
    {
        return (string) $this->runner()->run(new SaveAssignmentOption(), $input, $actor);
    }

    /**
     * Ties the set to its one request field (`kalaka/tie-request-field`, S44-1).
     *
     * @throws \Kalaka\Foundation\Api\Error\RuleViolation when the set has another request field, or the key
     *     is another set's
     * @throws \Kalaka\Foundation\Api\Error\KalakaException anything else the run throws
     */
    public function tieRequestField(int $setId, string $fieldKey, Actor $actor): void
    {
        $this->runner()->run(new TieRequestField(), ['set_id' => $setId, 'field_key' => $fieldKey], $actor);
    }

    /** @param list<OptionRecord> $options the options of the set's event */
    private static function toSet(SetRecord $set, array $options): AssignmentSet
    {
        $own = [];
        foreach ($options as $option) {
            if ($option->setId === $set->id) {
                $own[] = $option->toOption();
            }
        }

        return new AssignmentSet(
            $set->id,
            $set->eventId,
            $set->version,
            $set->name,
            $set->timeSlot,
            $set->required,
            $set->mode,
            $set->published,
            $set->requestFieldKey,
            $own,
        );
    }

    private function runner(): Runner
    {
        return $this->runner ?? Runner::current();
    }
}
