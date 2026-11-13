<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * One participant field of an event: what the event knows about a participant under one key. Form fields
 * join it by holding its key. `type` is the field block's name (e.g. `kalaka/text-field`), `title` the
 * latest title a form gave it (it may hold the title's inline HTML), `source` one of `form`, `staff`,
 * `extension` and `system` (a `system` field is read-only: no form holds it). [UNAPPROVED N1]
 */
final readonly class ParticipantField
{
    /** @param list<array{value: string, label: string, retired: bool}> $options empty for a field without options */
    public function __construct(
        public string $key,
        public string $type,
        public string $title,
        public array $options,
        public string $source,
        public bool $retired,
    ) {
    }

    /**
     * @return array{
     *     key: string,
     *     type: string,
     *     title: string,
     *     options: list<array{value: string, label: string, retired: bool}>,
     *     source: string,
     *     retired: bool,
     * }
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type,
            'title' => $this->title,
            'options' => $this->options,
            'source' => $this->source,
            'retired' => $this->retired,
        ];
    }
}
