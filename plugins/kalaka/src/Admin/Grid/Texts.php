<?php

declare(strict_types=1);

namespace Kalaka\Admin\Grid;

/**
 * The Hungarian text of the grid page that PHP renders or hands to the app (S37). The app's own strings are
 * in `app/grid/texts.js`.
 */
final class Texts
{
    /** The submenu item, the page title and the full-access default tab. */
    public const REGISTRANTS = 'Jelentkezők';

    /** The column labels of Kaláka's own columns. */
    public const CODE = 'Kód';
    public const STATUS = 'Állapot';
    // [UNAPPROVED T1] The column label only: „Sorszám” was too long for the column (owner's walk, topic 6a G5).
    public const SERIAL = 'Szám';

    /** A given consent in a cell and as the counted member; a shown but unticked one (stored '0'). */
    public const YES = 'Igen';
    /** A shown but unticked consent (a stored "0") in a cell. */
    public const NO = 'Nem';

    /** A parameter term in a view's condition: the value is given at each grant. */
    public const PER_GRANT = 'a hozzáférésnél megadott érték';

    /** A grant's tab with a bound value: the view, a no-break space, an en dash, the value. */
    public const TAB_BOUND = "%1\$s\u{00A0}– %2\$s";

    /** A grant holder with no view on the event. */
    public const NO_VIEW = 'Ehhez az eseményhez nincs nézeted.';

    /** The ability of `kalaka/save-master-counts`. */
    public const MASTER_COUNTS_LABEL = 'Összesítés mentése';
    public const MASTER_COUNTS_DESCRIPTION =
        'Annak beállítása, hogy egy oszlopban mit számoljon a Jelentkezők összesítő sora.';
}
