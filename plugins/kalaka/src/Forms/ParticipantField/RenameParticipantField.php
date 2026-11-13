<?php

declare(strict_types=1);

namespace Kalaka\Forms\ParticipantField;

use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Changes a participant field's title, the field's own and separate from the labels of the form blocks that
 * hold it (owner ruling 2026-11-13). The only way a title changes after the field is declared: the form
 * editor calls it when the organiser chooses to carry a block's new label over, and staff from the field
 * list. A `form` or `staff` field, retired ones included; a `system` or `extension` field's title is its
 * declarer's, so it is refused. Site-level: full access only. [UNAPPROVED N10]
 */
final class RenameParticipantField implements AdminUseCase
{
    public function __construct(
        private readonly ParticipantFieldRepository $repository = new ParticipantFieldRepository(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/rename-participant-field';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,63}$'],
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'pattern' => '\\S'],
            ],
            'required' => ['event_id', 'key', 'title'],
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

    /**
     * @throws NotFound for a key the event has no participant field for
     * @throws RuleViolation for a `system` or `extension` field
     */
    public function execute(array $input, Context $context): ParticipantFieldRecord
    {
        $record = $this->repository->findForUpdate(
            ['eventId' => (int) $input['event_id'], 'key' => (string) $input['key']],
            $context,
        );
        if ($record === null) {
            throw new NotFound();
        }
        $sources = [ParticipantFieldRecord::SOURCE_FORM, ParticipantFieldRecord::SOURCE_STAFF];
        if (!in_array($record->source, $sources, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a key and a source, never output
            throw new RuleViolation('participant field ' . $record->key . ' is ' . $record->source);
        }
        $title = trim((string) $input['title']);
        if ($record->title !== $title) {
            $record->title = $title;
            $record->updatedAt = $context->now();
            $this->repository->upsert($record, $context);
        }

        return $record;
    }
}
