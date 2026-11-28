<?php

declare(strict_types=1);

namespace Kalaka\Assignments;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Creates a request-mode assignment set of an event, or replaces an existing set's settings (with the version it was
 * read at): name, time slot, required and the published switch. A set has no choice window (owner ruling 2026-11-25):
 * the form's own open period and conditions decide when it is answered. The event and the mode never change, and the
 * request field is tied only by `kalaka/tie-request-field`. A site-level use case: full access only (screens are
 * S72/S48's; until then WP-CLI and the seed).
 */
final class SaveAssignmentSet implements AdminUseCase
{
    public function __construct(
        private readonly SetRepository $repository = new SetRepository(),
        private readonly Events $events = new Events(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/save-assignment-set';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'version' => ['type' => 'integer', 'minimum' => 1],
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'name' => ['type' => 'string'],
                'time_slot' => ['type' => ['string', 'null']],
                'required' => ['type' => 'boolean'],
                'published' => ['type' => 'boolean'],
            ],
            'required' => ['event_id', 'name', 'required', 'published'],
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

    /** @return int the set's ID */
    public function execute(array $input, Context $context): int
    {
        $eventId = (int) $input['event_id'];
        $name = self::text($input['name'], true);
        $timeSlot = $input['time_slot'] ?? null;
        $timeSlot = $timeSlot === null ? null : self::text($timeSlot, false);

        if (isset($input['id'])) {
            if (!isset($input['version'])) {
                throw new InvalidInput();
            }
            $record = $this->repository->findForUpdate((int) $input['id'], $context) ?? throw new NotFound();
            if ($record->eventId !== $eventId) {
                throw new InvalidInput();
            }
            if ($record->version !== (int) $input['version']) {
                throw new VersionConflict();
            }
        } else {
            if ($this->events->find($eventId) === null) {
                throw new NotFound();
            }
            $record = new SetRecord($eventId, $name);
        }

        $record->name = $name;
        $record->timeSlot = $timeSlot === '' ? null : $timeSlot;
        $record->required = (bool) $input['required'];
        $record->published = (bool) $input['published'];

        return $this->repository->upsert($record, $context)->id;
    }

    /** The text trimmed, at most 191 characters; a name is not empty. */
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
