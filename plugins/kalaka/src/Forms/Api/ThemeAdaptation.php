<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * What a theme adapter returns when it applies: data only, from closed lists (modules-and-extensions.md,
 * "Contracts"). Extra classes per element role, the wrapper of a single-control field and the error
 * colour. Core applies them to the markup it renders; the adapter never changes that markup itself.
 */
final readonly class ThemeAdaptation
{
    /** One CSS class name. */
    private const CLASS_PATTERN = '/\A-?[_a-zA-Z][_a-zA-Z0-9-]*\z/';

    /** A hex colour (`#rgb` or `#rrggbb`) or a custom property (`var(--name)`). */
    private const COLOUR_PATTERN = '/\A(#[0-9a-fA-F]{3}|#[0-9a-fA-F]{6}|var\(--[a-zA-Z0-9_-]+\))\z/';

    /** @var array<string, list<string>> */
    private array $classes;

    /**
     * @param array<string, list<string>> $classes extra classes by `ElementRole` value
     * @param string|null $errorColour null keeps the theme's own error colour, or the fixed red
     * @throws \InvalidArgumentException on an unknown role, a string that is not one class name, or a colour
     *     that is neither `#rgb`, `#rrggbb` nor `var(--name)`
     */
    public function __construct(
        array $classes = [],
        public FieldWrapper $wrapper = FieldWrapper::Div,
        public ?string $errorColour = null,
    ) {
        foreach ($classes as $role => $names) {
            if (ElementRole::tryFrom($role) === null) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an adapter's role name, never output
                throw new \InvalidArgumentException('Unknown element role ' . $role . '.');
            }
            foreach ($names as $name) {
                if (preg_match(self::CLASS_PATTERN, $name) !== 1) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a role name, never output
                    throw new \InvalidArgumentException('Not one class name for role ' . $role . '.');
                }
            }
        }
        if ($errorColour !== null && preg_match(self::COLOUR_PATTERN, $errorColour) !== 1) {
            throw new \InvalidArgumentException('Not an error colour.');
        }
        $this->classes = $classes;
    }

    /** @return list<string> */
    public function classes(ElementRole $role): array
    {
        return $this->classes[$role->value] ?? [];
    }
}
