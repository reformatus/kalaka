<?php

declare(strict_types=1);

namespace Kalaka\Admin\Grid;

use Kalaka\Registrations\Api\RegistrationStatus;

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
    public const SERIAL = 'Sorszám';

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

    /** A status as the grid shows it: `RegistrationStatus::word()` with a capital. */
    public static function status(RegistrationStatus $status): string
    {
        $word = $status->word();

        return mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($word, 1, null, 'UTF-8');
    }
}
