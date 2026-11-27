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
     * The top-level item. [UNAPPROVED T270] „Kaláka” is the name the paused banner uses. Its page title, which is the
     * browser tab's, is `SITE_PAGE`, as the page's h1 is.
     */
    public const SITE_MENU = 'Kaláka';

    /** The site page and its submenu item: the page the approved paused banner names („Kaláka › Levelezés”). */
    public const SITE_PAGE = 'Levelezés';

    /** The event's submenu item and its mail page. [UNAPPROVED T271] */
    public const EVENT_PAGE = 'Levelek';

    /**
     * The `kalaka_mail` editor's overrides of core's „Közzététel” wording: the msgid in core's own English, then the
     * Hungarian shown. The progress label is `Publishing…` (with the ellipsis), not the Preferences heading's bare
     * `Publishing`, which is no longer overridden. The `Publish` override also renames the sidebar's date row and its
     * date popover title, which share the msgid and cannot be excluded per use: boxed, not excluded. `Published` is
     * also set before `wp-editor` loads (`TemplateEditor::editorAssets()`), because the sidebar's Állapot row reads
     * it once at load.
     *
     * @var array<string, string>
     */
    public const EDITOR_WORDS = [
        'Publish' => 'Elérhetővé tétel',
        'Are you ready to publish?' => 'Elérhetővé teszed a levélsablont?',
        'Publishing…' => 'Elérhetővé tétel…',
        'Publish:' => 'Elérhetővé tétel:',
        'Double-check your settings before publishing.' => 'Mielőtt elérhetővé teszed, nézd át a beállításokat.',
        'Published' => 'Elérhető',
        'is now live.' => 'mostantól elérhető.',
    ];

    /**
     * The editor's back button's label, tooltip and name, when the template has an event.
     */
    public const EDITOR_BACK = 'Vissza az esemény leveleihez';

    /**
     * The back button's label when the template has no event (it lands on the site's „Levelezés” page). Proposed in
     * the lektor's style; marked for the lektor's next pass. [UNAPPROVED T918]
     */
    public const EDITOR_BACK_SITE = 'Vissza a Levelezés oldalra';
}
