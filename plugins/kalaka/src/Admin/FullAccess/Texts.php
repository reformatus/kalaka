<?php

declare(strict_types=1);

namespace Kalaka\Admin\FullAccess;

/** The Hungarian UI text of full access on the WordPress user screens and its refusal. */
final class Texts
{
    /** The section heading on the profile and user-edit screens. */
    public const SECTION = 'Kaláka';

    /** The row label. */
    public const ROW = 'Teljes hozzáférés';

    /** The checkbox label. */
    public const CHECKBOX = 'Hozzáfér a Kaláka minden eseményéhez és minden jelentkező adataihoz';

    /** The help text under the checkbox. */
    public const HELP = 'Csak annak add meg, aki a Kaláka egészét kezeli. '
        . 'Az adminisztrátori szerepkör nem tartalmazza.';

    /** The column title on the Users list. */
    public const COLUMN = 'Kaláka';

    /** The column's cell for a holder. */
    public const CELL = 'Teljes hozzáférés';

    /** Added to `RuleViolation`'s text when the last holder would lose full access. */
    public const LAST_HOLDER = 'Az utolsó teljes hozzáférést nem veheted el. '
        . 'Előbb adj teljes hozzáférést valaki másnak.';
}
