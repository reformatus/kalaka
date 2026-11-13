<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * Fits participant-side markup to a theme that styles its own components instead of WordPress's,
 * contributed through `Contributions::themeAdapter()`. An outside point (modules-and-extensions.md,
 * "The extension surface"): it reads no request and writes nothing, and one that throws or returns
 * something invalid is skipped, so the form renders as if no adapter applied. At most one adapter
 * applies: the first, in contribution order, that returns an adaptation. The adapter loads the
 * theme's own styles itself, through the theme's own mechanisms, in its module's `boot()`.
 */
interface ThemeAdapter
{
    /** The adaptation for the active theme, or null when this adapter does not apply to it. */
    public function adapt(ThemeAdapterInput $input): ?ThemeAdaptation;
}
