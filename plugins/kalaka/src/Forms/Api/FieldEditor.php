<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * What a screen needs to edit one stored answer by hand: the field type's kind (its block name, which control to
 * open), the answer the control opens with (the type's `prefill()` of the stored value, of the JSON type
 * `answer()` takes; null opens it empty) and, for a type with options, the field's active options.
 */
final readonly class FieldEditor
{
    public const CHOICE = 'choice';

    public const MULTI_CHOICE = 'multi_choice';

    public const CONSENT = 'consent';

    public const TEXT = 'text';

    public const NUMBER = 'number';

    /**
     * @param list<array{value: string, label: string}>|null $options null for a type without options
     * @param bool $multiple a choice that takes several options
     * @param string|null $control one of the constants above, or null when the type has no simple control
     *     (a ranking, the congregation picker, a type of an extension)
     */
    public function __construct(
        public string $kind,
        public mixed $answer,
        public ?array $options,
        public bool $multiple = false,
        public ?string $control = null,
    ) {
    }
}
