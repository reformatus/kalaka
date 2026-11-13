<?php

declare(strict_types=1);

namespace Kalaka\Forms\ParticipantField;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Changes a participant field's title and option labels, the field's own and separate from the labels the
 * forms show (owner rulings 2026-11-13). The only way either changes after it is declared: the form editor
 * calls it when the organiser chooses to carry a block's new text over, and staff from the field list.
 * Options are matched by value, retired ones included; a value the field does not have is refused (a new
 * option comes only from a form). A `form` or `staff` field, retired ones included; a `system` or
 * `extension` field's texts are its declarer's, so it is refused. Site-level: full access only.
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
                'options' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'value' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9_-]{0,63}$'],
                            'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'pattern' => '\\S'],
                        ],
                        'required' => ['value', 'label'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['event_id', 'key'],
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
     * @throws InvalidInput with neither a title nor options, or for an option value the field does not have
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
        if (!isset($input['title']) && !isset($input['options'])) {
            throw new InvalidInput('neither a title nor options');
        }
        $before = $record->toRow();
        if (isset($input['title'])) {
            $record->title = trim((string) $input['title']);
        }
        /** @var list<array{value: string, label: string}> $labels */
        $labels = $input['options'] ?? [];
        $options = $record->options ?? [];
        foreach ($labels as $change) {
            $found = false;
            foreach ($options as $i => $option) {
                if ($option['value'] === $change['value']) {
                    $options[$i]['label'] = trim($change['label']);
                    $found = true;
                }
            }
            if (!$found) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an option value, never output
                throw new InvalidInput('option ' . $change['value'] . ' of ' . $record->key);
            }
        }
        if ($labels !== []) {
            $record->options = $options;
        }
        if ($record->toRow() !== $before) {
            $record->updatedAt = $context->now();
            $this->repository->upsert($record, $context);
        }

        return $record;
    }
}
