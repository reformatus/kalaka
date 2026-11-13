<?php

declare(strict_types=1);

namespace Kalaka\Forms\ParticipantField;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Time\Instant;

/**
 * One participant field of an event: a row of `kalaka_participant_fields`, keyed by (event ID, field key).
 * The type is the field block's name; `position` is the declaration order within the event (forms in the
 * order the sync reads them, blocks in document order); `formWritable` is an `extension` field's
 * declaration that forms may hold it (false by default; `form` and `staff` fields are always writable from a
 * form, `system` fields never). A choice field's options are JSON, `[{value, label, retired_at}]`,
 * in the order they were first declared; a retired option keeps its label. Every instant is UTC.
 */
final class ParticipantFieldRecord
{
    public const TABLE = 'kalaka_participant_fields';

    public const SOURCE_FORM = 'form';
    public const SOURCE_STAFF = 'staff';
    public const SOURCE_EXTENSION = 'extension';
    public const SOURCE_SYSTEM = 'system';

    public const SOURCES = [self::SOURCE_FORM, self::SOURCE_STAFF, self::SOURCE_EXTENSION, self::SOURCE_SYSTEM];

    /**
     * @param ?list<array{value: string, label: string, retired_at: ?string}> $options null for a field without
     *     options; `retired_at` is the stored UTC instant or null
     */
    public function __construct(
        public readonly int $eventId,
        public readonly string $key,
        public string $type,
        public string $title,
        public ?array $options,
        public string $source,
        public ?\DateTimeImmutable $retiredAt,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public int $position = 0,
        public bool $formWritable = false,
        public int $version = 0,
    ) {
    }

    /** Whether a form may hold this field's key (owner ruling 2026-11-13). */
    public function writableFromForms(): bool
    {
        return match ($this->source) {
            self::SOURCE_SYSTEM => false,
            self::SOURCE_EXTENSION => $this->formWritable,
            default => true,
        };
    }

    /** @param array<string, ?string> $row a `kalaka_participant_fields` row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['event_id'],
            (string) $row['field_key'],
            (string) $row['type'],
            (string) $row['title'],
            $row['options'] === null ? null : self::decodeOptions($row['options']),
            (string) $row['source'],
            $row['retired_at'] === null ? null : Instant::fromStorage($row['retired_at']),
            Instant::fromStorage((string) $row['created_at']),
            Instant::fromStorage((string) $row['updated_at']),
            (int) $row['position'],
            $row['form_writable'] === '1',
            (int) $row['version'],
        );
    }

    /** @return array<string, int|string|null> every column in its stored form */
    public function toRow(): array
    {
        return [
            'event_id' => $this->eventId,
            'field_key' => $this->key,
            'version' => $this->version,
            'type' => $this->type,
            'title' => $this->title,
            'options' => $this->options === null ? null : self::encodeOptions($this->options),
            'source' => $this->source,
            'retired_at' => $this->retiredAt === null ? null : Instant::toStorage($this->retiredAt),
            'created_at' => Instant::toStorage($this->createdAt),
            'updated_at' => Instant::toStorage($this->updatedAt),
            'position' => $this->position,
            'form_writable' => $this->formWritable ? 1 : 0,
        ];
    }

    /** @param list<array{value: string, label: string, retired_at: ?string}> $options */
    private static function encodeOptions(array $options): string
    {
        return (string) wp_json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<array{value: string, label: string, retired_at: ?string}> */
    private static function decodeOptions(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            $error = new SystemFailure(null, new \UnexpectedValueException('Stored options are not a list.'));
            throw $error;
        }
        $options = [];
        foreach ($decoded as $option) {
            if (
                !is_array($option)
                || !is_string($option['value'] ?? null)
                || !is_string($option['label'] ?? null)
                || !(is_string($option['retired_at'] ?? null) || ($option['retired_at'] ?? null) === null)
            ) {
                $error = new SystemFailure(null, new \UnexpectedValueException('A stored option is malformed.'));
                throw $error;
            }
            $options[] = [
                'value' => $option['value'],
                'label' => $option['label'],
                'retired_at' => $option['retired_at'],
            ];
        }

        return $options;
    }
}
