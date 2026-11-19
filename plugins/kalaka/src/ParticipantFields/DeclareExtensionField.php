<?php

declare(strict_types=1);

namespace Kalaka\ParticipantFields;

use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * An extension declares one participant field of an event, source `extension` (events-and-forms.md,
 * "Fields"): its type, title, choice options and whether forms may hold its key. The field's texts are its
 * declarer's, so a declaration sets the title and the option labels as given; an option it no longer
 * declares is retired with its label kept, and one declared again is active again. A key no row has yet
 * is added after every field declared before. A key that is held by another source or with another type is
 * refused: the declaration never takes over a form's, staff or system field. Run nested in the declarer's own
 * use case (`ParticipantFields::declareExtensionField()`), e.g. the price ladder's save. [UNAPPROVED, #200 N10]
 */
final class DeclareExtensionField implements AdminUseCase
{
    public function __construct(
        private readonly ParticipantFieldRepository $repository = new ParticipantFieldRepository(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/declare-extension-field';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,63}$'],
                'type' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
                'title' => ['type' => 'string', 'minLength' => 1],
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
                'form_writable' => ['type' => 'boolean'],
            ],
            'required' => ['event_id', 'key', 'type', 'title', 'options', 'form_writable'],
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

    /** @throws RuleViolation when the key is held by another source or with another type */
    public function execute(array $input, Context $context): null
    {
        $eventId = (int) $input['event_id'];
        $key = (string) $input['key'];
        $now = $context->now();
        /** @var ?list<array{value: string, label: string}> $declared */
        $declared = $input['options'];

        $record = null;
        $position = 0;
        foreach ($this->repository->forEventForUpdate($eventId, $context) as $stored) {
            $position = max($position, $stored->position);
            if ($stored->key === $key) {
                $record = $stored;
            }
        }

        if ($record === null) {
            $this->repository->upsert(new ParticipantFieldRecord(
                $eventId,
                $key,
                (string) $input['type'],
                (string) $input['title'],
                self::options(null, $declared, $now),
                ParticipantFieldRecord::SOURCE_EXTENSION,
                null,
                $now,
                $now,
                $position + 1,
                (bool) $input['form_writable'],
            ), $context);

            return null;
        }
        if ($record->source !== ParticipantFieldRecord::SOURCE_EXTENSION || $record->type !== $input['type']) {
            throw new RuleViolation();
        }

        $before = $record->toRow();
        $record->title = (string) $input['title'];
        $record->options = self::options($record->options, $declared, $now);
        $record->formWritable = (bool) $input['form_writable'];
        $record->retiredAt = null;
        if ($record->toRow() !== $before) {
            $record->updatedAt = $now;
            $this->repository->upsert($record, $context);
        }

        return null;
    }

    /**
     * The declared options with their labels, active, in the stored order and then the new ones; a stored
     * option not declared any more retired, its label kept.
     *
     * @param ?list<array{value: string, label: string, retired_at: ?string}> $stored
     * @param ?list<array{value: string, label: string}> $declared
     * @return ?list<array{value: string, label: string, retired_at: ?string}>
     */
    private static function options(?array $stored, ?array $declared, \DateTimeImmutable $now): ?array
    {
        if ($declared === null && $stored === null) {
            return null;
        }
        $labels = [];
        foreach ($declared ?? [] as $option) {
            $labels[$option['value']] = $option['label'];
        }
        $options = [];
        foreach ($stored ?? [] as $option) {
            $value = $option['value'];
            if (isset($labels[$value])) {
                $options[$value] = ['value' => $value, 'label' => $labels[$value], 'retired_at' => null];
                continue;
            }
            $options[$value] = $option['retired_at'] === null
                ? ['retired_at' => Instant::toStorage($now)] + $option
                : $option;
        }
        foreach ($labels as $value => $label) {
            $value = (string) $value;
            $options[$value] ??= ['value' => $value, 'label' => $label, 'retired_at' => null];
        }

        return array_values($options);
    }
}
