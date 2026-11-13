<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/** What a theme adapter is asked with: the active theme. */
final readonly class ThemeAdapterInput
{
    /**
     * @param string $stylesheet the active theme's directory name (the child theme, if one is active)
     * @param string $template the parent theme's directory name (the same as `$stylesheet` without a child theme)
     */
    public function __construct(
        public string $stylesheet,
        public string $template,
    ) {
    }
}
