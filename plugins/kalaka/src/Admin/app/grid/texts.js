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
	resetSort: 'Alapértelmezett sorrend visszaállítása (sorszám, majd kód szerint)',
	resetSortShort: 'Alapértelmezett sorrend visszaállítása',
	// A failed table or sum-row load, in the table's place. (Shared with #210's N10.)
	loadFail: 'A táblázat betöltése nem sikerült.',
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
	// While the grid is narrowed, and only to full access (the setting is stored for everyone). [UNAPPROVED N29]
	pickNote: 'A beállítás a teljes nézetre érvényes.',
};

/** The average of a scale field: a new text. */
export const SUM_AVERAGE = 'Átlag: %s';
