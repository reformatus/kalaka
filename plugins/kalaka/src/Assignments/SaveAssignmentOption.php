<?php

declare(strict_types=1);

namespace Kalaka\Assignments;

use Kalaka\Forms\Api\KeyDerivation;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Adds an option to a set, or replaces an option's settings (with the version it was read at). A new option's
 * key is the one given, or derived once from its label like a choice option's value (`KeyDerivation::value()`,
 * unique within the set); a key never changes. An option holds no condition: who may pick it is
 * decided in the form's ranking block. A site-level use case, like `kalaka/save-assignment-set`.
 */
final class SaveAssignmentOption implements AdminUseCase
{
    public function __construct(
        private readonly SetRepository $sets = new SetRepository(),
        private readonly OptionRepository $options = new OptionRepository(),
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
                'capacity' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 50000],
                'leaders' => ['type' => 'string'],
                'place' => ['type' => 'string'],
                'position' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 65535],
            ],
            'required' => ['set_id', 'label', 'capacity', 'leaders', 'place', 'position'],
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
        $label = self::text($input['label'], true);

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
        $record->capacity = $input['capacity'] === null ? null : (int) $input['capacity'];
        $record->leaders = self::text($input['leaders'], false);
        $record->place = self::text($input['place'], false);
        $record->position = (int) $input['position'];

        return $this->options->upsert($record, $context)->key;
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
