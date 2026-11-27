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
	// The one reset (search, filters, sort and counting): DataViews' own, already approved „Visszaállítás”
	// (`Translations::OVERRIDES`), so no new string. [UNAPPROVED T38]
	reset: 'Visszaállítás',
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
};

/** The sum row. */
export const SUM = {
	rows: '%d sor',
	members: '%1$s (%2$s): %3$d jelentkező',
	count: '%1$s: %2$d',
	filled: 'Kitöltve: %d',
	total: 'Összesen: %s',
	// The number in a sum cell opens the counting menu; its counting group and its screen-reader name.
	// [UNAPPROVED T3, T4]
	counting: 'Számolás',
	open: 'Összesítés: %s',
	// The sum row's own load failure, above a table whose rows are on screen. [UNAPPROVED T6]
	loadFail: 'Az összesítő sor betöltése nem sikerült.',
};

/** The average of a scale field: a new text. */
export const SUM_AVERAGE = 'Átlag: %s';

/**
 * The detail panel (S38). Every string is the prototype's text after the Opus lektor, approved by the owner; the tab
 * names are Adatok, Kérelmek, Pénzügyek, of which this branch has Pénzügyek.
 */
export const PANEL = {
	panel: 'Jelentkező részletei',
	close: 'Bezárás',
	tabsLabel: 'Részletek',
	tabMoney: 'Pénzügyek',
	// The confirmation row's two words: the confirmation decision's own state labels, as `ConfirmationRule` declares
	// them and the grid's cells already show.
	confirmed: 'Visszaigazolva',
	unconfirmed: 'Nincs visszaigazolva',
	loading: 'Betöltés…',
	loadFail: 'A részletek betöltése nem sikerült.',
	notFound: 'A jelentkező nem található.',
	// A known code outside the holder's views (403).
	noAccess: 'Ehhez a jelentkezőhöz nincs hozzáférésed.',
	// For screen readers: the row button's name, „<kód> – részletek”.
	openRow: '%s – részletek',
};

/**
 * The „Pénzügyek” tab and its two dialogs. Most words are the owner's (topic 10, 2026-11-21) and are built
 * verbatim, approved by the owner: the payer table, the entries and the dialog fields; the
 * actions. The title field's label and hint and the „completed immediately” checkboxes with their help are #209's
 * lektor-approved texts, built verbatim by #217: [UNAPPROVED #209 T28] (#217's T32 follows it). Approved by the
 * owner: the title's empty-field message, the snackbars of a saved pair, the pair's save button, the empty list,
 * „Mentés” and „Mégse”, the preview's empty „–”; the messages (without the correction's reason message, which is gone), the
 * screen-reader text; „0 Ft”.
 */
export const MONEY = {
	payer: 'Fizető',
	owed: 'Fizetendő',
	// The two sides (topic 10): „Fizetendő” and „Befizetett”, then „Tartozás” = Fizetendő − Befizetett.
	paid: 'Befizetett',
	balance: 'Tartozás',
	// A negative „Tartozás” in the panel: „−5 000 Ft (túlfizetés)”.
	overpaid: 'túlfizetés',
	total: 'Összesen',
	entries: 'Tételek',
	date: 'Dátum',
	type: 'Típus',
	method: 'Mód',
	note: 'Megjegyzés',
	// The empty list.
	none: 'Még nincs tétel.',
	correction: 'korrekció',
	// The owner's G12 words (topic 6a): one „Tétel rögzítése” dialog (topic 10: a payment, a refund, a charge or a
	// discount), and the correction on its own: the two buttons and the
	// correction's button.
	recordEntry: 'Tétel rögzítése',
	recordCorrect: 'Korrekció rögzítése',
	amountField: 'Összeg (Ft)',
	dateField: 'Dátum',
	methodField: 'Fizetési mód',
	noteField: 'Közlemény',
	reasonField: 'Megjegyzés', // the owner's 6.8 ruling: always „Megjegyzés”
	// The dialogs' preview (topic 10): „Tartozás” replaces „egyenleg”.
	balanceNow: 'Jelenlegi tartozás',
	balanceAfter: 'Tartozás mentés után',
	// The screen-reader text of the preview's empty „–”.
	noAmount: 'még nincs összeg',
	// The dialogs' buttons.
	save: 'Mentés',
	cancel: 'Mégse',
	// The payment snackbar's text.
	saved: 'Befizetés rögzítve.',
	// The refund and correction words and snackbars, approved by the owner 2026-11-20 (T1, T2, T4, T12, T15).
	savedRefund: 'Visszafizetés rögzítve.',
	savedCorrection: 'Korrekció rögzítve.',
	// The owner's topic 10 snackbars, built verbatim.
	savedCharge: 'Díj rögzítve.',
	savedDiscount: 'Kedvezmény rögzítve.',
	// The „Tétel rögzítése” dialog's first field and its four options (topic 10, the owner's words), with no
	// default unless only one type is allowed.
	typeField: 'Típus',
	entryIn: 'Befizetés',
	entryOut: 'Visszafizetés',
	entryCharge: 'Díj',
	entryDiscount: 'Kedvezmény',
	typeEmpty: 'Válassz típust.',
	// The correction dialog's direction (the lektor's form, approved by the owner, topic 10) and its message.
	directionField: 'Irány',
	directionIn: 'Befizetett összeg növelése',
	directionOut: 'Befizetett összeg csökkentése',
	directionEmpty: 'Válassz irányt.',
	// A charge's or a discount's title, which the participant sees: the label and hint are #209's lektor-approved
	// text, built verbatim by #217. [UNAPPROVED #209 T28]
	titleField: 'Megnevezés',
	titleHint: 'Ezt a jelentkező is látja.',
	// The empty-field message is approved by the owner.
	titleEmpty: 'Add meg a megnevezést.',
	// „Completed immediately” (the owner's addendum, topic 10): the checkboxes and their help are #209's
	// lektor-approved text, built verbatim by #217. [UNAPPROVED #209 T28]
	completedCharge: 'Azonnal befizetve',
	completedDiscount: 'Azonnal visszafizetve',
	completedChargeHelp: 'A díjjal együtt ugyanekkora befizetést is rögzít, így a tartozás nem változik.',
	completedDiscountHelp: 'A kedvezménnyel együtt ugyanekkora visszafizetést is rögzít, így a tartozás nem változik.',
	// The save button when both lines are saved (approved by the owner).
	saveTwo: '2 tétel rögzítése',
	// The snackbars when both lines are saved (approved by the owner).
	savedChargePaid: 'Díj és befizetés rögzítve.',
	savedDiscountRefund: 'Kedvezmény és visszafizetés rögzítve.',
	amountEmpty: 'Add meg az összeget.',
	amountBad: 'Nullánál nagyobb összeget adj meg.',
	dateEmpty: 'Add meg a dátumot.',
	dateFuture: 'A dátum nem lehet jövőbeli.',
	methodEmpty: 'Válassz fizetési módot.',
	payerEmpty: 'Válassz fizetőt.',
	saveFail: 'A mentés nem sikerült. Próbáld újra.',
};
