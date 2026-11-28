<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * What a theme adapter returns when it applies: data only, from closed lists (modules-and-extensions.md,
 * "Contracts"). Extra classes per element role, the wrapper of a single-control field, the error
 * colour, the content frame of a page core renders itself and the body classes of that page to drop. Core applies
 * them to the markup it renders; the adapter never changes that markup itself.
 */
final readonly class ThemeAdaptation
{
    /** One CSS class name. */
    private const CLASS_PATTERN = '/\A-?[_a-zA-Z][_a-zA-Z0-9-]*\z/';

    /** A hex colour (`#rgb` or `#rrggbb`) or a custom property (`var(--name)`). */
    private const COLOUR_PATTERN = '/\A(#[0-9a-fA-F]{3}|#[0-9a-fA-F]{6}|var\(--[a-zA-Z0-9_-]+\))\z/';

    /** @var array<string, list<string>> */
    private array $classes;

    /** @var list<string> */
    private array $pageBodyOff;

    /**
     * @param array<string, list<string>> $classes extra classes by `ElementRole` value
     * @param string|null $errorColour null keeps the theme's own error colour, or the fixed red
     * @param ContentFrame $frame the frame around a page core renders itself (the card return page)
     * @param list<string> $pageBodyOff body classes the theme gives that page that its frame stands in place of
     *     (the classes to add are the role `ElementRole::PageBody`'s) [UNAPPROVED N826]
     * @throws \InvalidArgumentException on an unknown role, a string that is not one class name, or a colour
     *     that is neither `#rgb`, `#rrggbb` nor `var(--name)`
     */
    public function __construct(
        array $classes = [],
        public FieldWrapper $wrapper = FieldWrapper::Div,
        public ?string $errorColour = null,
        public ContentFrame $frame = ContentFrame::Neutral,
        array $pageBodyOff = [],
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
        foreach ($pageBodyOff as $name) {
            if (preg_match(self::CLASS_PATTERN, $name) !== 1) {
                throw new \InvalidArgumentException('Not one class name to drop from the page body.');
            }
        }
        $this->classes = $classes;
        $this->pageBodyOff = array_values(array_unique($pageBodyOff));
    }

    /** @return list<string> */
    public function pageBodyOff(): array
    {
        return $this->pageBodyOff;
    }

    /** @return list<string> */
    public function classes(ElementRole $role): array
    {
        return $this->classes[$role->value] ?? [];
    }
}
