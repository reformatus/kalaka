<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * The frame a theme puts a page's content in, chosen by a theme adapter from this closed list; core renders it
 * around the markup it produces itself where no theme page holds it (the card return page). The adapter
 * returns a case, never HTML (modules-and-extensions.md, "Contracts"). [UNAPPROVED N820]
 */
enum ContentFrame: string
{
    /** One plain wrapper, no look of our own: the default. */
    case Neutral = 'neutral';
    /**
     * Divi's `page.php` frame, its ids held here as fixed markup:
     * `#main-content` › `.container` › `#content-area.clearfix` › `#left-area`.
     */
    case Page = 'page';

    /** The opening tags of the frame, outermost first (fixed markup of core's). */
    public function open(): string
    {
        return match ($this) {
            self::Neutral => '<div class="kalaka-card-return-frame">',
            self::Page => '<div id="main-content"><div class="container">'
                . '<div id="content-area" class="clearfix"><div id="left-area">',
        };
    }

    /** The closing tags of the frame, innermost first. */
    public function close(): string
    {
        return match ($this) {
            self::Neutral => '</div>',
            self::Page => '</div></div></div></div>',
        };
    }
}
