<?php

declare(strict_types=1);

namespace Kalaka\Admin\Event;

/**
 * The Hungarian text of the event's „Esemény adatai” page. Every string is an unapproved box: the page's
 * name, the two field labels, the save button, the current-value line and the two notices. [UNAPPROVED T1002
 * to T1009]
 */
final class Texts
{
    /** The submenu item and the page title. [UNAPPROVED T1002] */
    public const TITLE = 'Esemény adatai';

    /** The start field's label. [UNAPPROVED T1003] */
    public const START = 'Kezdés';

    /** The end field's label. [UNAPPROVED T1004] */
    public const END = 'Befejezés';

    /** The submit button. [UNAPPROVED T1005] */
    public const SAVE = 'Mentés';

    /** The line under the fields: the event's current start and end in the site's zone. [UNAPPROVED T1006] */
    public const CURRENT = 'Jelenleg: %1$s – %2$s.';

    /** The current-value line while neither point is set. [UNAPPROVED T1007] */
    public const NO_TIMES = 'A kezdés és a befejezés nincs megadva.';

    /** The notice after a successful save. [UNAPPROVED T1008] */
    public const SAVED = 'Az esemény adatai elmentve.';

    /** The notice after a refused save. [UNAPPROVED T1009] */
    public const FAILED = 'A mentés nem sikerült. Ellenőrizd a dátumokat, majd próbáld újra.';

    /** The help line under the two fields. [UNAPPROVED T1010] */
    public const HELP = 'A dátum és az idő a webhely időzónája szerint értendő.';

    /** A user without full access. [UNAPPROVED T1011] */
    public const DENIED = 'Ehhez nincs jogosultságod.';
}
