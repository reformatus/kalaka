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
	// The reset of the sort and the counting only; the search, the filters and DataViews' own Reset are theirs.
	// [UNAPPROVED T38]
	reload: 'Rendezés és számolás visszaállítása',
	// Filters and search. [UNAPPROVED N10]
	followsFilter: 'Az összesítő sor és a másolás a szűrt sorokra vonatkozik.',
	noMatch: 'Nincs megfelelő jelentkező.',
	// A view or a filter set with no registrations at all. [UNAPPROVED N10]
	emptyView: 'Nincs jelentkező.',
	// One more value in a list column's own filter; the owner's own word (2026-11-21).
	unanswered: 'Nincs válasz',
	// A failed table load, in the table's place.
	loadFail: 'A táblázat betöltése nem sikerült.',
	found: '%d jelentkező',
	none: 'Nincs jelentkező',
	// Under the Sorszám range, approved by the owner 2026-11-18. [UNAPPROVED N11]
	serialHint: 'Például 1–50: az első 50 jelentkező',
};

/** The sum row. */
export const SUM = {
	rows: '%d sor',
	members: '%1$s (%2$s): %3$d jelentkező',
	count: '%1$s: %2$d',
	filled: 'Kitöltve: %d',
	total: 'Összesen: %s',
	// The arrow of a sum cell opens the column's menu; its counting group. [UNAPPROVED T3, T4]
	counting: 'Számolás',
	open: '%s oszlopmenüje',
	// The sum row's own load failure, above a table whose rows are on screen. [UNAPPROVED T6]
	loadFail: 'Az összesítő sor betöltése nem sikerült.',
};

/** The hint under Kód's sort options in the column's menu. [UNAPPROVED T37] */
export const COLUMN = {
	codeAsc: 'Jelentkezés sorrendjében',
	codeDesc: 'Jelentkezés fordított sorrendjében',
};

/** The average of a scale field: a new text. */
export const SUM_AVERAGE = 'Átlag: %s';

/**
 * The detail panel (S38). Every string is the prototype's text after the Opus lektor, and unapproved until the
 * owner ticks its box: [UNAPPROVED T6] the panel, [UNAPPROVED T3] the disabled tab.
 */
export const PANEL = {
	panel: 'Jelentkező részletei',
	close: 'Bezárás',
	tabsLabel: 'Részletek',
	tabMoney: 'Pénzügyek',
	// [UNAPPROVED T3]: the lektor suggested „Hamarosan” with the screen-reader text „További lapok hamarosan”.
	tabSoon: 'Hamarosan…',
	loading: 'Betöltés…',
	loadFail: 'A részletek betöltése nem sikerült.',
	notFound: 'A jelentkező nem található.',
	// A known code outside the holder's views (403). [UNAPPROVED T16]
	noAccess: 'Ehhez a jelentkezőhöz nincs hozzáférésed.',
	// For screen readers [UNAPPROVED T13]: the row button's name, „<kód> – részletek”.
	openRow: '%s – részletek',
};

/**
 * The „Pénzügyek” tab and its two dialogs. [UNAPPROVED T7] the payer table, [UNAPPROVED T8] the entries,
 * [UNAPPROVED T9] the actions, [UNAPPROVED T10] the dialog fields, [UNAPPROVED T11] the messages,
 * [UNAPPROVED T12] the snackbar, [UNAPPROVED T13] the screen-reader text. The refund and correction words are
 * [UNAPPROVED T1] and [UNAPPROVED T2]; „0 Ft” is [UNAPPROVED T5].
 */
export const MONEY = {
	payer: 'Fizető',
	owed: 'Fizetendő',
	paid: 'Befizetve',
	balance: 'Egyenleg',
	total: 'Összesen',
	due: 'hátralék',
	over: 'túlfizetés',
	entries: 'Tételek',
	date: 'Dátum',
	type: 'Típus',
	amount: 'Összeg',
	method: 'Mód',
	note: 'Megjegyzés',
	none: 'Még nincs tétel.',
	correction: 'korrekció',
	recordPay: 'Befizetés rögzítése',
	recordRefund: 'Visszautalás / javítás rögzítése',
	amountField: 'Összeg (Ft)',
	dateField: 'Dátum',
	methodField: 'Fizetési mód',
	noteField: 'Közlemény',
	reasonField: 'Indoklás',
	balanceNow: 'Jelenlegi egyenleg',
	balanceAfter: 'Egyenleg mentés után',
	noAmount: 'még nincs összeg',
	save: 'Mentés',
	cancel: 'Mégse',
	saved: 'Befizetés rögzítve.',
	savedRefund: 'Visszafizetés rögzítve.',
	// The refund / correction dialog's switch, direction and messages. [UNAPPROVED T15]
	kindField: 'Típus',
	kindRefund: 'Visszafizetés',
	kindCorrection: 'Korrekció',
	directionField: 'Irány',
	directionIn: 'Befizetésként',
	directionOut: 'Kifizetésként',
	directionEmpty: 'Válassz irányt.',
	savedCorrection: 'Korrekció rögzítve.',
	amountEmpty: 'Add meg az összeget.',
	amountBad: 'Nullánál nagyobb összeget adj meg.',
	dateEmpty: 'Add meg a dátumot.',
	dateFuture: 'A dátum nem lehet jövőbeli.',
	methodEmpty: 'Válassz fizetési módot.',
	payerEmpty: 'Válassz fizetőt.',
	noteRequired: 'Add meg az indoklást.',
	saveFail: 'A mentés nem sikerült. Próbáld újra.',
};
