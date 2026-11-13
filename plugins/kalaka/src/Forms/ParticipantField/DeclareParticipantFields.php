<?php

declare(strict_types=1);

namespace Kalaka\Forms\ParticipantField;

use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Brings an event's participant fields in line with the fields its forms hold [UNAPPROVED N5]. The input is
 * the union over every form of the event that is not trashed, the saved form's fields marked `latest`:
 *
 * - a key no row has yet is declared, source `form`;
 * - a held key keeps its row and type; one held with a different type, or a field forms may not hold
 *   (`system`, or `extension` not declared form-writable), is skipped and
 *   returned (the save check lets neither through, so only a form written around it gets here) and the
 *   rest goes on; a retired one is active again; its title and option labels stay the ones it was declared
 *   with (`RenameParticipantField` changes them); `latest` only orders the union, so a new option takes the
 *   saved form's label first;
 * - choice options are a union: a new one is added, one no form holds any more is retired with its label
 *   kept (owner ruling 2026-11-13, B3(a)), and one held again is active again;
 * - a `form` field no form holds any more is retired. Its key is never derived again (`FieldKeys`).
 *
 * `staff`, `extension` and `system` fields are not retired here, and neither are their options.
 * Site-level: forms are edited by full access only.
 */
final class DeclareParticipantFields implements AdminUseCase
{
    public function __construct(
        private readonly ParticipantFieldRepository $repository = new ParticipantFieldRepository(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/declare-participant-fields';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'fields' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,63}$'],
                            'type' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
                            'title' => ['type' => 'string'],
                            'options' => [
                                'type' => ['array', 'null'],
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'value' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9_-]{0,63}$'],
                                        'label' => ['type' => 'string'],
                                    ],
                                    'required' => ['value', 'label'],
                                    'additionalProperties' => false,
                                ],
                            ],
                            'latest' => ['type' => 'boolean'],
                        ],
                        'required' => ['key', 'type', 'title', 'options', 'latest'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['event_id', 'fields'],
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

    /** @return list<string> the keys it skipped: a different type than the stored field, or a `system` field */
    public function execute(array $input, Context $context): array
    {
        $eventId = (int) $input['event_id'];
        $now = $context->now();
        $rows = [];
        foreach ($this->repository->forEventForUpdate($eventId, $context) as $record) {
            $rows[$record->key] = $record;
        }
        // A new field comes after every field declared before, in the order the input lists them.
        $position = 0;
        foreach ($rows as $record) {
            $position = max($position, $record->position);
        }

        $held = [];
        $skipped = [];
        /** @var list<array{key: string, type: string, title: string, options: ?list<array{value: string, label: string}>, latest: bool}> $fields */
        $fields = $input['fields'];
        foreach ($fields as $field) {
            $key = $field['key'];
            $held[$key] = true;
            $record = $rows[$key] ?? null;
            if ($record === null) {
                $this->repository->upsert(new ParticipantFieldRecord(
                    $eventId,
                    $key,
                    $field['type'],
                    $field['title'],
                    self::merged(null, $field['options'], true, $now),
                    ParticipantFieldRecord::SOURCE_FORM,
                    null,
                    $now,
                    $now,
                    ++$position,
                ), $context);
                continue;
            }
            if (!$record->writableFromForms() || $record->type !== $field['type']) {
                // A form written around the save check: the stored field stays as it is, the rest goes on.
                $skipped[] = $key;
                continue;
            }

            $before = $record->toRow();
            $record->retiredAt = null;
            // The title is the participant field's own: set when it is declared, changed only by
            // `RenameParticipantField` (owner ruling 2026-11-13). [UNAPPROVED N10]
            $record->options = self::merged(
                $record->options,
                $field['options'],
                $record->source === ParticipantFieldRecord::SOURCE_FORM,
                $now,
            );
            $this->save($record, $before, $now, $context);
        }

        foreach ($rows as $key => $record) {
            $retirable = $record->source === ParticipantFieldRecord::SOURCE_FORM && $record->retiredAt === null;
            if (isset($held[$key]) || !$retirable) {
                continue;
            }
            $before = $record->toRow();
            $record->retiredAt = $now;
            $this->save($record, $before, $now, $context);
        }

        return $skipped;
    }

    /**
     * Writes a record whose columns changed, with the time of the change.
     *
     * @param array<string, int|string|null> $before
     */
    private function save(
        ParticipantFieldRecord $record,
        array $before,
        \DateTimeImmutable $now,
        Context $context,
    ): void {
        if ($record->toRow() === $before) {
            return;
        }
        $record->updatedAt = $now;
        $this->repository->upsert($record, $context);
    }

    /**
     * The stored options with the held ones: a held option is active and keeps its stored label (the
     * participant field's own, owner ruling 2026-11-13; a form shows its own labels); a new one is added at
     * the end with the label it is first declared with; one no longer held is retired with its label (only
     * when `$retire`). [UNAPPROVED N11]
     *
     * @param ?list<array{value: string, label: string, retired_at: ?string}> $stored
     * @param ?list<array{value: string, label: string}> $held
     * @return ?list<array{value: string, label: string, retired_at: ?string}>
     */
    private static function merged(
        ?array $stored,
        ?array $held,
        bool $retire,
        \DateTimeImmutable $now,
    ): ?array {
        if ($stored === null && $held === null) {
            return null;
        }
        /** @var array<string, array{value: string, label: string, retired_at: ?string}> $options by value, in order */
        $options = [];
        foreach ($stored ?? [] as $option) {
            $options[$option['value']] = $option;
        }
        $seen = [];
        foreach ($held ?? [] as $option) {
            $value = $option['value'];
            $seen[$value] = true;
            $known = $options[$value] ?? null;
            $options[$value] = [
                'value' => $value,
                'label' => $known === null ? $option['label'] : $known['label'],
                'retired_at' => null,
            ];
        }
        if ($retire) {
            foreach ($options as $value => $option) {
                if (!isset($seen[$value]) && $option['retired_at'] === null) {
                    $option['retired_at'] = Instant::toStorage($now);
                    $options[$value] = $option;
                }
            }
        }

        return array_values($options);
    }
}
