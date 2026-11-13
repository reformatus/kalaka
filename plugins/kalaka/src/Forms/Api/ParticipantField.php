<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * One participant field of an event: what the event knows about a participant under one key. Form fields
 * join it by holding its key. `type` is the field block's name (e.g. `kalaka/text-field`) and `typeLabel`
 * the title that block type is registered with (the approved block titles for core's types, an extension's
 * own title for its types, the block name when the type is not registered). `title` is the latest title a
 * form gave it (it may hold the title's inline HTML), `source` one of `form`, `staff`, `extension` and
 * `system`, `formWritable` whether a form may hold its key, and `forms` the forms of the event that hold it
 * now (trashed ones not). [UNAPPROVED N1] [UNAPPROVED N9]
 */
final readonly class ParticipantField
{
    /**
     * @param list<array{value: string, label: string, retired: bool}> $options empty for a field without options
     * @param list<array{form_id: int, title: string}> $forms
     */
    public function __construct(
        public string $key,
        public string $type,
        public string $title,
        public array $options,
        public string $source,
        public bool $retired,
        public string $typeLabel = '',
        public bool $formWritable = true,
        public array $forms = [],
    ) {
    }

    /**
     * @return array{
     *     key: string,
     *     type: string,
     *     type_label: string,
     *     title: string,
     *     options: list<array{value: string, label: string, retired: bool}>,
     *     source: string,
     *     form_writable: bool,
     *     retired: bool,
     *     forms: list<array{form_id: int, title: string}>,
     * }
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type,
            'type_label' => $this->typeLabel,
            'title' => $this->title,
            'options' => $this->options,
            'source' => $this->source,
            'form_writable' => $this->formWritable,
            'retired' => $this->retired,
            'forms' => $this->forms,
        ];
    }
}
