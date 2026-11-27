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
    // [UNAPPROVED T1] The column label only: „Sorszám” was too long for the column (owner's walk, topic 6a G5).
    public const SERIAL = 'Szám';
    /** The first submission's time and the moment the registration became registered. [UNAPPROVED T18, T19] lektored */
    public const SUBMITTED_AT = 'Jelentkezés ideje';
    public const REGISTERED_AT = 'Rögzítés ideje';
    /** The title of the awaits-an-answer column: the panel's „Válaszra vár” ([UNAPPROVED T852], reused from #232 T4). */
    public const AWAITS_ANSWER = 'Válaszra vár';

    /** A given consent in a cell and as the counted member; a shown but unticked one (stored '0'). */
    public const YES = 'Igen';
    /** A shown but unticked consent (a stored "0") in a cell. */
    public const NO = 'Nem';
    /** A cell that does not apply (N/A), different from „Nem”: the awaits-an-answer column of a registration that is not registered. [UNAPPROVED T1120] */
    public const NOT_APPLICABLE = '–';

    /** A parameter term in a view's condition: the value is given at each grant. */
    public const PER_GRANT = 'a hozzáférésnél megadott érték';

    /** A grant's tab with a bound value: the view, a no-break space, an en dash, the value. */
    public const TAB_BOUND = "%1\$s\u{00A0}– %2\$s";

    /** A grant holder with no view on the event. */
    public const NO_VIEW = 'Ehhez az eseményhez nincs nézeted.';

    /**
     * A status word as the grid shows it: `RegistrationStatus::word()` with a capital letter, as N14 approved.
     * The word set stays the server's; this is only its display casing.
     */
    public static function status(RegistrationStatus $status): string
    {
        $word = $status->word();

        return mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($word, 1, null, 'UTF-8');
    }
}
