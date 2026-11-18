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
	manage: 'Nézetek kezelése',
	more: 'Több',
	moreLabel: 'További nézetek',
	tabsLabel: 'Nézetek',
	toolbar: 'Műveletek',
	resetSort: 'Alapértelmezett sorrend visszaállítása (sorszám, majd kód szerint)',
	resetSortShort: 'Alapértelmezett sorrend visszaállítása',
};

/** The sum row. */
export const SUM = {
	rows: '%d sor',
	members: '%1$s (%2$s): %3$d jelentkező',
	pick: 'Mit számoljon?',
	pickLabel: '%s: a számolt értékek',
	count: '%1$s: %2$d',
	filled: 'Kitöltve: %d',
	total: 'Összesen: %s',
};

/** The average of a scale field: a new text. */
export const SUM_AVERAGE = 'Átlag: %s';

/** The „Nézetek” dialog. */
export const VIEWS = {
	dialog: 'Nézetek',
	count: '%d jelentkező',
	filter: 'Feltétel',
	filters: 'Feltételek',
	columns: 'Oszlopok',
	grants: 'Hozzáférések',
	noGrants: 'Senki sem fér hozzá.',
	edit: 'Szerkesztés',
	del: 'Törlés',
	name: 'Név',
	removeParam: 'Feltétel eltávolítása',
	save: 'Mentés',
	cancel: 'Mégse',
	emptyFull: 'Ehhez az eseményhez még nincs nézet.',
	emptyGrant: 'Ehhez az eseményhez nincs nézeted.',
	// A1–A3 (S36-4), as the lektor passed them and the owner ruled „as built”.
	a1: 'Ezt a nézetet %d felhasználó használja. Mentés után mindenki, aki hozzáfér, a módosított nézetet látja.',
	a2Title: 'A felhasználók a teljes nézetet látják majd',
	a2: 'A feltétel (%1$s) értékét %2$d hozzáférés maga adja meg. Ha eltávolítod a feltételt, vagy másik oszlopra teszed, minden ilyen felhasználó a nézet összes jelentkezőjét látja majd, nem csak a sajátjait.',
	a2Ok: 'Mentés így is',
	a3Title: 'Nézet törlése',
	a3: 'Ezt a nézetet (%1$s) %2$d felhasználó használja. A nézettel együtt a hozzáférései is törlődnek.',
	a3Ok: 'Törlés',
};
