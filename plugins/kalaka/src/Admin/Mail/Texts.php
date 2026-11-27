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

    /**
     * The event's „Levelek” page, rebuilt in PHP without tabs (owner 2026-11-27, walkthrough 8; section 8 of the build
     * list). The words it keeps are approved and reused as they are: „Tárgy”, „Állapot”, „Módosítva”, „Szerkesztés”,
     * „Elérhető”, „Vázlat”, „Levélkeret”, „A levélkeret HTML-je”, „Mentés”, „Mégse”, „Bezárás”, „Csoportos levelek”,
     * „Küldésre vár”, „Elküldve”, „Sikertelen”, „Jelentkező”, „Levél”, „Kihagyva”, „Sorszám”, „Sorba állítva”,
     * „%d. csoportos levél részletei” and the held sentence (the site page's and the old tabs'). Every new word is
     * boxed below.
     */
    public const SECTION_POINTS = 'Küldési pontok'; // [UNAPPROVED T1260]

    public const SECTION_UNASSIGNED = 'Hozzá nem rendelt sablonok'; // [UNAPPROVED T1261]

    public const OVERVIEW_TITLE = 'Áttekintés'; // [UNAPPROVED T1262]

    public const POINT_COLUMN = 'Küldési pont'; // [UNAPPROVED T1263]

    public const SWITCH_COLUMN = 'Bekapcsolva'; // [UNAPPROVED T1264]

    /** The template cell of a point without a template. [UNAPPROVED T1265] */
    public const NO_TEMPLATE = 'Nincs levélsablon.';

    /** The choose button and its dialog's title. [UNAPPROVED T1266] */
    public const CHOOSE_TEMPLATE = 'Sablon kiválasztása';

    /** The new-template button. [UNAPPROVED T1267] */
    public const NEW_TEMPLATE = 'Új sablon';

    /** The X's accessible name. [UNAPPROVED T1268] */
    public const REMOVE_TEMPLATE = 'Sablon eltávolítása';

    /** The clear-queued act's confirm, on the overview card. [UNAPPROVED T1269] */
    public const CLEAR_CONFIRM = 'Az esemény minden küldésre váró levele törlődik a küldési sorból.';

    /** The way back from the levélkeret screen. [UNAPPROVED T1270] */
    public const BACK_TO_PAGE = 'Vissza az esemény leveleihez';

    /** The unassigned section with nothing in it. [UNAPPROVED T1271] */
    public const NONE_UNASSIGNED = 'Nincs hozzá nem rendelt sablon.';

    /** The overview's link to the full failure list. [UNAPPROVED T1272] */
    public const SENDER_LINK = 'A hibák teljes listája a Levelezés oldalon.';

    /** An act that failed, with no server text of its own. [UNAPPROVED T1273] */
    public const ACT_FAIL = 'A művelet nem sikerült. Próbáld újra.';

    // The words the page keeps (approved elsewhere; not re-boxed):
    public const TEMPLATE_COLUMN = 'Levélsablon';
    public const TEMPLATE_STATUS = 'Állapot';
    public const USES_FRAME = 'Levélkeret használata';
    public const MODIFIED = 'Módosítva';
    public const EDIT = 'Szerkesztés';
    public const YES = 'Igen';
    public const NO = 'Nem';
    public const NONE = '–';
    public const SAVE = 'Mentés';
    public const CANCEL = 'Mégse';
    public const CLOSE = 'Bezárás';
    public const PUBLISHED = 'Elérhető';
    public const DRAFT = 'Vázlat';
    public const PENDING = 'Függőben';
    public const SCHEDULED = 'Időzítve';
    public const PRIVATE_STATUS = 'Privát';
    public const HELD = 'Az esemény nincs közzétéve, ezért a levelek a küldési sorban várnak.';
    public const QUEUED = 'Küldésre vár';
    public const SENT = 'Elküldve';
    public const FAILED = 'Sikertelen';
    public const STUCK = 'Küldés közben elakadt';
    public const WITH_ERROR = 'A küldésre várók közül hiba után vár';
    public const TROUBLED_TITLE = 'Küldési hibák';
    public const TROUBLED_CODE = 'Jelentkező';
    public const TROUBLED_MAIL = 'Levél';
    public const TROUBLED_STATUS = 'Állapot';
    public const TROUBLED_ERROR = 'Hibaüzenet';
    public const BATCHES_TITLE = 'Csoportos levelek';
    public const BATCH_NUMBER = 'Sorszám';
    public const BATCH_SUBJECT = 'Tárgy';
    public const BATCH_QUEUED = 'Sorba került';
    public const BATCH_SKIPPED = 'Kihagyva';
    public const BATCH_OPEN = '%d. csoportos levél részletei';
    public const BATCH_MANUAL = '%d. csoportos levél';
    public const BATCH_DETAILS_LABEL = 'Részletek';
    public const NUMBERED = '%1$s (%2$d. számú levélsablon)';
    public const LAYOUT_LINK = 'Levélkeret';
    public const LAYOUT_TITLE = 'Levélkeret';
    public const LAYOUT_FIELD = 'A levélkeret HTML-je';
    public const CLEAR_QUEUED = 'Az esemény küldésre váró leveleinek törlése';
    public const DELIVERY_FAILED = self::FAILED;
    public const DELIVERY_SENDING = self::STUCK;
    public const DELIVERY_PENDING = self::WITH_ERROR;
}
