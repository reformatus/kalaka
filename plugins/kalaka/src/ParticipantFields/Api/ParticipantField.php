<?php

declare(strict_types=1);

namespace Kalaka\ParticipantFields\Api;

/**
 * One participant field of an event: what the event knows about a participant under one key. Form fields
 * join it by holding its key. `type` is the field block's name (e.g. `kalaka/text-field`) and `typeLabel`
 * the title that block type is registered with (the approved block titles for core's types, an extension's
 * own title for its types, the block name when the type is not registered). `title` is the field's own:
 * set when the field is first declared and changed only by `RenameParticipantField` (it may hold the
 * title's inline HTML), `source` one of `form`, `staff`, `extension` and
 * `system`, `formWritable` whether a form may hold its key, and `forms` the forms of the event that hold it
 * now (trashed ones not): `ParticipantFields::of()` names none, `Forms::participantFields()` adds them
 * (`withForms()`). `moneyRelevant` is whether its value is locked after the first write (declared by an extension
 * for its field, or by the block type for a form field). `declaredBy` is the slug of the extension that declared
 * an `extension` field (null for any other source, or a row stored before the slug existed).
 */
final readonly class ParticipantField
{
    public const SOURCE_FORM = 'form';
    public const SOURCE_STAFF = 'staff';
    public const SOURCE_EXTENSION = 'extension';
    public const SOURCE_SYSTEM = 'system';

    /**
     * @param list<array{value: string, label: string, retired: bool}> $options empty for a field without options
     * @param list<array{form_id: int, title: string}> $forms
     * @param array<string, mixed> $settings the type's settings that outlive the form (the number field's bounds,
     *     decimals and affixes)
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
        public array $settings = [],
        public bool $moneyRelevant = false,
        public ?string $declaredBy = null,
    ) {
    }

    /**
     * The same field with the forms that hold it.
     *
     * @param list<array{form_id: int, title: string}> $forms
     */
    public function withForms(array $forms): self
    {
        return new self(
            $this->key,
            $this->type,
            $this->title,
            $this->options,
            $this->source,
            $this->retired,
            $this->typeLabel,
            $this->formWritable,
            $forms,
            $this->settings,
            $this->moneyRelevant,
            $this->declaredBy,
        );
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
     *     settings: array<string, mixed>,
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
            'settings' => $this->settings,
        ];
    }
}
