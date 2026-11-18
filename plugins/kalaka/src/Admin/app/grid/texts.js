/**
 * The Hungarian text of the grid page's app (S37). PHP's own text is in `Admin/Grid/Texts.php`.
 * `%d`, `%s` and `%1$s` are filled by `fmt()`.
 */

/** The grid page: tabs, toolbar, copy, paging, sorting. */
export const GRID = {
	copy: 'Táblázat másolása',
	copied: '%d sor a vágólapra másolva.',
	copyFail: 'A másolás nem sikerült.',
	perPage: 'Oldalanként',
	more: 'Több',
	moreLabel: 'További nézetek',
	tabsLabel: 'Nézetek',
	toolbar: 'Műveletek',
	// Filters and search. [UNAPPROVED N10]
	reload: 'Rendezés, szűrők és keresés visszaállítása',
	followsFilter: 'Az összesítő sor és a másolás a szűrt sorokra vonatkozik.',
	noMatch: 'Nincs megfelelő jelentkező.',
	// A view or a filter set with no registrations at all. [UNAPPROVED N10]
	emptyView: 'Nincs jelentkező.',
	unanswered: 'Nincs válasz',
	removeFilter: 'Szűrő eltávolítása: %s',
	// A failed table load, in the table's place. (Shared with #210's N10.)
	loadFail: 'A táblázat betöltése nem sikerült.',
	found: '%d jelentkező',
	none: 'Nincs jelentkező',
	// Under the Sorszám range, approved by the owner 2026-11-18. [UNAPPROVED N11]
	serialHint: 'Például 1–50: az első 50 jelentkező',
	// The view's own condition is a locked chip in the filter area (owner's walk, topic 6a G3). [UNAPPROVED T1]
	lockedFilter: 'A nézet feltétele, nem szerkeszthető.',
};

/** The sum row. */
export const SUM = {
	rows: '%d sor',
	members: '%1$s (%2$s): %3$d jelentkező',
	count: '%1$s: %2$d',
	filled: 'Kitöltve: %d',
	total: 'Összesen: %s',
	// The counting choices joined the column's own menu; these are the shorter words. [UNAPPROVED T3, T4]
	counting: 'Számolás',
	open: 'Számolás beállítása: %s',
	// While filtered or searched. [UNAPPROVED N10]
	pickNote: 'A beállítás a teljes nézetre érvényes.',
	// The sum row's own load failure, above a table whose rows are on screen. [UNAPPROVED T6]
	loadFail: 'Az összesítő sor betöltése nem sikerült.',
};

/** The average of a scale field: a new text. */
export const SUM_AVERAGE = 'Átlag: %s';

/** The view's own condition, read-only, in the filter area. */
export const VIEWS = {
	filter: 'Feltétel',
	filters: 'Feltételek',
};
