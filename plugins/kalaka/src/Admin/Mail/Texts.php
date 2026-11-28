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

    /**
     * The sender page's settings section, the form of the hourly budget. The heading is a candidate, not approved.
     * [UNAPPROVED T1302]
     */
    public const SETTINGS = 'Küldési beállítások';

    /** The budget's label: the twin of `SENDER.budget` in `app/mail/texts.js`, as the status block names it. */
    public const BUDGET = 'Óránként legfeljebb küldhető levél';

    /** The default shown beside the field, as text: the twin of `SENDER.budgetDefault` (printf, one number). */
    public const BUDGET_DEFAULT = 'Alapértelmezés: %d';

    /** The form's submit button: the twin of `COMMON.save`. */
    public const SAVE = 'Mentés';
}
