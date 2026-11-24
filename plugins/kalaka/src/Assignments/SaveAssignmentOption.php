<?php

declare(strict_types=1);

namespace Kalaka\Assignments;

use Kalaka\Forms\Api\Availability;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Assignments\Api\LiveFormWouldBreak;
use Kalaka\Forms\Api\KeyDerivation;
use Kalaka\Forms\Api\RequestScope;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\ParticipantFields\Api\ParticipantFields;

/**
 * Adds an option to a set, or replaces an option's settings (with the version it was read at). A new option's
 * key is the one given, or derived once from its label like a choice option's value (`KeyDerivation::value()`,
 * unique within the set); a key never changes. The eligibility condition is in the one condition format and is
 * checked when saved (`Availability::check()`) against what a fact source of the event declares: the event's
 * participant fields that are not retired, with their types, and the event's keys (`EventValues`), and against
 * every published form that holds the set's request field, as that form would read it
 * (`LiveFormWouldBreak`). A site-level use case, like `kalaka/save-assignment-set`.
 */
final class SaveAssignmentOption implements AdminUseCase
{
    /**
     * @param list<RequestScope>|null $liveForms where the published forms of the set's event hold its request
     *     field, read before the run (`Forms::requestScopes()`, through `Api\AssignmentSets::saveOption()`):
     *     a use case reads no WordPress post inside its transaction. Null is a caller that did not read them.
     */
    public function __construct(
        private readonly ?array $liveForms = null,
        private readonly SetRepository $sets = new SetRepository(),
        private readonly OptionRepository $options = new OptionRepository(),
        private readonly ParticipantFields $fields = new ParticipantFields(),
        private readonly Availability $availability = new Availability(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/save-assignment-option';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'set_id' => ['type' => 'integer', 'minimum' => 1],
                'key' => ['type' => 'string'],
                'version' => ['type' => 'integer', 'minimum' => 1],
                'label' => ['type' => 'string'],
                'limit' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 50000],
                'leaders' => ['type' => 'string'],
                'place' => ['type' => 'string'],
                'condition' => ['type' => ['object', 'null']],
                'position' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 65535],
            ],
            'required' => ['set_id', 'label', 'limit', 'leaders', 'place', 'condition', 'position'],
            'additionalProperties' => false,
        ];
    }

    public function action(): ?string
    {
        return null;
    }

    public function event(array $input): ?int
    {
        return null;
    }

    public function targets(array $input): array
    {
        return [];
    }

    /** @return string the option's key */
    public function execute(array $input, Context $context): string
    {
        $set = $this->sets->findForUpdate((int) $input['set_id'], $context) ?? throw new NotFound();
        if ($this->liveForms === null) {
            $error = new SystemFailure(null, new \LogicException('The live forms of the set were not read first.'));
            throw $error;
        }
        $label = self::text($input['label'], true);
        $condition = self::condition($input['condition']);
        if ($condition !== null) {
            try {
                $this->availability->check($condition, $this->declared($set->eventId));
            } catch (SystemFailure $e) {
                // An invalid condition, or one reading a key the event does not have, is the caller's input.
                $error = new InvalidInput(null, $e);
                throw $error;
            }
            $this->checkLiveForms($set->id, $condition);
        }

        $taken = [];
        foreach ($this->options->ofEvent($set->eventId) as $existing) {
            if ($existing->setId === $set->id) {
                $taken[$existing->key] = true;
            }
        }
        $key = isset($input['key']) ? (string) $input['key'] : KeyDerivation::value($label, $taken);
        if (preg_match(KeyDerivation::VALUE_PATTERN, $key) !== 1) {
            throw new InvalidInput();
        }

        $record = $this->options->findForUpdate(['setId' => $set->id, 'key' => $key], $context);
        if ($record === null) {
            $record = new OptionRecord($set->id, $key, $set->eventId, $label);
        } elseif (!isset($input['version']) || $record->version !== (int) $input['version']) {
            throw new VersionConflict();
        }
        $record->label = $label;
        $record->limit = $input['limit'] === null ? null : (int) $input['limit'];
        $record->leaders = self::text($input['leaders'], false);
        $record->place = self::text($input['place'], false);
        $record->condition = $condition;
        $record->position = (int) $input['position'];

        return $this->options->upsert($record, $context)->key;
    }

    /**
     * The keys a condition on the event's registrations may read: its participant fields (not retired), each
     * as its field type's value type, and the event's keys.
     *
     * @return array<string, ValueType>
     */
    private function declared(int $eventId): array
    {
        $types = Contributions::current()->fieldTypes();
        $declared = EventValues::TYPES;
        foreach ($this->fields->of($eventId) as $field) {
            $type = $types[$field->type] ?? null;
            if ($type !== null) {
                $declared[$field->key] = $type->valueType();
            }
        }

        return $declared;
    }

    /**
     * Refuses a condition that a published form holding the set's request field could not read there:
     * the form would break, so the save is refused and the form stays as it was.
     *
     * @param array<array-key, mixed> $condition
     * @throws LiveFormWouldBreak
     */
    private function checkLiveForms(int $setId, array $condition): void
    {
        foreach ($this->liveForms ?? [] as $scope) {
            if ($scope->setId !== $setId) {
                $error = new SystemFailure(null, new \LogicException('The live forms were read for another set.'));
                throw $error;
            }
            try {
                $this->availability->checkInForm($condition, $scope);
            } catch (SystemFailure $e) {
                $error = new LiveFormWouldBreak(null, $e);
                throw $error;
            }
        }
    }

    /** @return array<array-key, mixed>|null */
    private static function condition(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        // The schema's object arrives as an array; an empty object is no condition.
        return is_array($value) && $value !== [] ? $value : throw new InvalidInput();
    }

    /** The text trimmed, at most 191 characters; a label is not empty. */
    private static function text(mixed $value, bool $nonEmpty): string
    {
        $text = trim((string) $value);
        $length = mb_strlen($text, 'UTF-8');
        if ($length > 191 || ($nonEmpty && $length < 1)) {
            throw new InvalidInput();
        }

        return $text;
    }
}
