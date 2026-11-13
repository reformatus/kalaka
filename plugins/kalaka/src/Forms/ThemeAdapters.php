<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Api\ThemeAdaptation;
use Kalaka\Forms\Api\ThemeAdapterInput;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * Picks the theme adapter for the active theme, once per request: the first contributed adapter, in
 * contribution order, that returns an adaptation. An adapter that throws, or whose adaptation is invalid
 * (`ThemeAdaptation` refuses it), is logged and skipped (modules-and-extensions.md, "Failure isolation"),
 * so the form renders as with no adapter.
 */
final class ThemeAdapters
{
    private static ?ThemeAdaptation $current = null;

    /** The adaptation in force: the adapter's, or none (no extra classes, a `div` wrapper, the theme's colour). */
    public static function current(): ThemeAdaptation
    {
        return self::$current ??= self::pick(
            Contributions::current(),
            new ThemeAdapterInput(get_stylesheet(), get_template())
        );
    }

    /**
     * Forgets the adaptation picked in this request; tests and a theme switch within one request call it.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$current = null;
    }

    private static function pick(Contributions $contributions, ThemeAdapterInput $input): ThemeAdaptation
    {
        foreach ($contributions->themeAdapters() as $adapter) {
            $adaptation = null;
            $ask = static function () use ($adapter, $input, &$adaptation): void {
                $adaptation = $adapter->adapt($input);
            };
            Boundary::background('theme adapter ' . $adapter::class, $ask);
            if ($adaptation instanceof ThemeAdaptation) {
                return $adaptation;
            }
        }

        return new ThemeAdaptation();
    }
}
