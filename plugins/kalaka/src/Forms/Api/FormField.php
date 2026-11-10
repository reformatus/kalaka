<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Forms\Condition\Condition;

/** A field block of a form's definition, with the attributes as authored. */
final readonly class FormField
{
    public const CONTACT_EMAIL_BLOCK = 'kalaka/email-field';
    public const DISPLAY_NAME_BLOCK = 'kalaka/name-field';

    /**
     * @param array<string, mixed> $attributes
     * @param list<FormBranch> $branches the branches the field sits in, outermost first; none outside any
     * @param array<string, Condition> $optionConditions a choice field's option value → its availability
     *     condition (S76), for the options that have one; passed along outside Forms, never inspected (N1)
     */
    public function __construct(
        public string $key,
        public string $blockName,
        public array $attributes,
        public array $branches = [],
        public array $optionConditions = [],
    ) {
    }

    public function label(): string
    {
        $label = $this->attributes['label'] ?? '';

        return is_string($label) ? $label : '';
    }

    public function isContactEmail(): bool
    {
        return $this->blockName === self::CONTACT_EMAIL_BLOCK && ($this->attributes['contactEmail'] ?? false) === true;
    }

    public function isDisplayName(): bool
    {
        return $this->blockName === self::DISPLAY_NAME_BLOCK && ($this->attributes['displayName'] ?? false) === true;
    }

    /** The two marked fields count as required whatever their `required` attribute. */
    public function isRequired(): bool
    {
        return ($this->attributes['required'] ?? false) === true || $this->isContactEmail() || $this->isDisplayName();
    }
}
