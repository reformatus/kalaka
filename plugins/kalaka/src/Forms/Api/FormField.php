<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/** A field block of a form's definition, with the attributes as authored. */
final readonly class FormField
{
    public const CONTACT_EMAIL_BLOCK = 'kalaka/email-field';
    public const DISPLAY_NAME_BLOCK = 'kalaka/name-field';

    /** @param array<string, mixed> $attributes */
    public function __construct(
        public string $key,
        public string $blockName,
        public array $attributes,
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
