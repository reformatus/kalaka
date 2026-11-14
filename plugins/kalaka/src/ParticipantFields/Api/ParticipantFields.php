<?php

declare(strict_types=1);

namespace Kalaka\ParticipantFields\Api;

use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\ParticipantFields\DeclareParticipantFields;
use Kalaka\ParticipantFields\ParticipantFieldQuery;
use Kalaka\ParticipantFields\RenameParticipantField;

/**
 * The event's participant fields, for every other module: the read, and the two writes, each run as its
 * audited use case through the runner (the `Registrations\Api\RegistrationSubmit` pattern). The read uses
 * Kaláka's own table and the block registry, so it runs outside a use case's transaction; so do the writes,
 * which open their own.
 */
final class ParticipantFields
{
    public function __construct(
        private readonly ?Runner $runner = null,
    ) {
    }

    /**
     * The event's participant fields, in the order they were declared (`position`); retired ones only when
     * asked. Each names its type's label (the block type's registered title, else the block name) and no
     * forms (`ParticipantField::$forms` is Forms' to add).
     *
     * @return list<ParticipantField>
     */
    public function of(int $eventId, bool $includeRetired = false): array
    {
        $blocks = \WP_Block_Type_Registry::get_instance();
        $fields = [];
        foreach ((new ParticipantFieldQuery())->forEvent($eventId) as $record) {
            if ($record->retiredAt !== null && !$includeRetired) {
                continue;
            }
            $options = [];
            foreach ($record->options ?? [] as $option) {
                $options[] = [
                    'value' => $option['value'],
                    'label' => $option['label'],
                    'retired' => $option['retired_at'] !== null,
                ];
            }
            $title = $blocks->get_registered($record->type)?->title;
            $fields[] = new ParticipantField(
                $record->key,
                $record->type,
                $record->title,
                $options,
                $record->source,
                $record->retiredAt !== null,
                is_string($title) && $title !== '' ? $title : $record->type,
                $record->writableFromForms(),
            );
        }

        return $fields;
    }

    /**
     * Brings the event's participant fields in line with the fields its forms hold
     * (`kalaka/declare-participant-fields`).
     *
     * @param list<array{
     *     key: string,
     *     type: string,
     *     title: string,
     *     options: ?list<array{value: string, label: string}>,
     *     latest: bool,
     * }> $fields the union over the event's forms, the saved form's marked `latest`
     * @return list<string> the keys it skipped: a different type than the stored field, or one forms may not hold
     * @throws \Kalaka\Foundation\Api\Error\KalakaException anything the run throws
     */
    public function declare(int $eventId, array $fields, Actor $actor): array
    {
        $skipped = $this->runner()->run(
            new DeclareParticipantFields(),
            ['event_id' => $eventId, 'fields' => $fields],
            $actor,
        );

        return array_values(array_filter(is_array($skipped) ? $skipped : [], 'is_string'));
    }

    /**
     * Changes a field's title and option labels (`kalaka/rename-participant-field`). The changes are passed
     * on as given, so the use case's input check judges them.
     *
     * @param array<string, mixed> $changes `title` and `options` (`[{value, label}]`), each only when given
     * @throws \Kalaka\Foundation\Api\Error\KalakaException anything the run throws: `NotFound` for a key the
     *     event does not have, `RuleViolation` for a `system` or `extension` field, `InvalidInput`
     */
    public function rename(int $eventId, string $key, array $changes, Actor $actor): void
    {
        $this->runner()->run(new RenameParticipantField(), ['event_id' => $eventId, 'key' => $key] + $changes, $actor);
    }

    private function runner(): Runner
    {
        return $this->runner ?? Runner::current();
    }
}
