<?php

declare(strict_types=1);

namespace Kalaka\Admin\Mail;

/**
 * The Hungarian text the mail pages' PHP shows. The app's own strings are in `app/mail/texts.js`. Every string
 * is unapproved until the owner ticks its box.
 */
final class Texts
{
    /**
     * The top-level item. „Kaláka” is the name the paused banner uses. Its page title, which is the
     * browser tab's, is `SITE_PAGE`, as the page's h1 is.
     */
    public const SITE_MENU = 'Kaláka';

    /** The site page and its submenu item: the page the approved paused banner names („Kaláka › Levelezés”). */
    public const SITE_PAGE = 'Levelezés';

    /** The event's submenu item and its mail page. */
    public const EVENT_PAGE = 'Levelek';
}
