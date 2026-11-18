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
    /** The submenu item, the page title and the full-access default tab. [UNAPPROVED N12] */
    public const REGISTRANTS = 'Jelentkezők';

    /** The column labels of Kaláka's own columns. [UNAPPROVED N14] */
    public const CODE = 'Kód';
    public const STATUS = 'Állapot';
    public const SERIAL = 'Sorszám';

    /** The confirmation decision: its column and its `confirmed` state. [UNAPPROVED N14] */
    public const CONFIRMATION = 'Visszaigazolás';
    public const CONFIRMED = 'Visszaigazolva';

    /** A given consent in a cell and as the counted member. [UNAPPROVED N14] */
    public const YES = 'Igen';

    /** A parameter term in a view's condition: the value is given at each grant. [UNAPPROVED N15] */
    public const PER_GRANT = 'a hozzáférésnél megadott értékkel';

    /** A grant holder with no view on the event. [UNAPPROVED N12] */
    public const NO_VIEW = 'Ehhez az eseményhez nincs nézeted.';

    /** The ability of `kalaka/save-master-counts`. [UNAPPROVED N16] */
    public const MASTER_COUNTS_LABEL = 'Összesítés mentése';
    public const MASTER_COUNTS_DESCRIPTION =
        'Annak beállítása, hogy egy oszlopban mit számoljon a Jelentkezők összesítő sora.';

    /** A status as the grid shows it: `RegistrationStatus::word()` with a capital. [UNAPPROVED N14] */
    public static function status(RegistrationStatus $status): string
    {
        $word = $status->word();

        return mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($word, 1, null, 'UTF-8');
    }
}
