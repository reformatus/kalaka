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
	// The two new tabs: „Adatok” was the field list's heading (T230), „Kérelmek” the requests block's (T3). [UNAPPROVED T951] [UNAPPROVED T952]
	tabFields: 'Adatok',
	tabRequests: 'Kérelmek',
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
 * The „Pénzügyek” tab and its two dialogs. Most words are the owner's (topic 10, 2026-11-21) and are built
 * verbatim, approved by the owner: the payer table, the entries and the dialog fields; [UNAPPROVED T9] the
 * actions. The title field's label and hint and the „completed immediately” checkboxes with their help are #209's
 * lektor-approved texts, built verbatim by #217: [UNAPPROVED #209 T28] (#217's T32 follows it). Approved by the
 * owner: the title's empty-field message, the snackbars of a saved pair, the pair's save button, the empty list,
 * „Mentés” and „Mégse”, the preview's empty „–”; [UNAPPROVED T11] the messages, [UNAPPROVED T13] the
 * screen-reader text; „0 Ft” is [UNAPPROVED T5].
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
	// discount), and the correction on its own. [UNAPPROVED T17] the two buttons, [UNAPPROVED T26] the
	// correction's button.
	recordEntry: 'Tétel rögzítése',
	recordCorrect: 'Korrekció rögzítése',
	amountField: 'Összeg (Ft)',
	dateField: 'Dátum',
	methodField: 'Fizetési mód',
	noteField: 'Közlemény',
	reasonField: 'Indoklás',
	// The dialogs' preview (topic 10): „Tartozás” replaces „egyenleg”.
	balanceNow: 'Jelenlegi tartozás',
	balanceAfter: 'Tartozás mentés után',
	// The screen-reader text of the preview's empty „–”. [UNAPPROVED T13]
	noAmount: 'még nincs összeg',
	// The dialogs' buttons.
	save: 'Mentés',
	cancel: 'Mégse',
	// [UNAPPROVED T18]: the payment snackbar's text.
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

/**
 * The manual confirmation (S20): the button, its two dialogs, the line that says who set it and the snackbars.
 * The decision's own labels („Visszaigazolás”, „Visszaigazolva”, „Nincs visszaigazolva”) come from the server and
 * are approved. Every string here is unapproved until the owner ticks its box: [UNAPPROVED T20] the set button and
 * its dialog title (T3 of #213), [UNAPPROVED T21] the field and its message, [UNAPPROVED T22] the line under the tab,
 * [UNAPPROVED T23] the snackbars, [UNAPPROVED T45] the message for a reason over the limit, [UNAPPROVED T46] the
 * snackbar after an unconfirm the rules re-confirmed at once. The unconfirm warning is the server's text (#213's
 * T5a, T5b, T5c, and T6 when that mail is on), not set here.
 */
export const CONFIRM = {
	set: 'Visszaigazolás befizetés nélkül',
	// The unconfirm button and its dialog title, approved by the owner 2026-11-20 (T24).
	unconfirm: 'Visszaigazolás visszavonása',
	reasonField: 'Indoklás',
	reasonTooLong: 'Az indoklás legfeljebb 500 karakter lehet.', // [UNAPPROVED T45]
	setLine: 'Befizetés nélkül visszaigazolta: %1$s, %2$s',
	setLineAnon: 'Befizetés nélkül visszaigazolva: %s',
	reasonLine: 'Indoklás: %s',
	saved: 'Befizetés nélküli visszaigazolás rögzítve.',
	unconfirmed: 'Visszaigazolás visszavonva.',
	// [UNAPPROVED T46] after the unconfirm, when the rules confirmed the registration again at once (the re-read says so).
	unconfirmedReconfirmed: 'Visszaigazolás visszavonva; a szabályok szerint újra visszaigazolva.',
};

/**
 * The block of requests (slice B, "answer a request"): the heading, the state of each request, the buttons and the
 * answer dialog. The words are the owner's topic-12 words (kérelem, válasz, megválaszol). Every string here is
 * unapproved until the owner ticks its box: [UNAPPROVED T3] the heading, [UNAPPROVED T4] „Válaszra vár”,
 * [UNAPPROVED T5] the states „Jóváhagyva” and „Elutasítva”, [UNAPPROVED T6] the award line, [UNAPPROVED T7] the
 * buttons, [UNAPPROVED T8] the dialog's title and fields, [UNAPPROVED T9] the snackbar, [UNAPPROVED T10] the full-offer
 * warning, [UNAPPROVED T11] the missing award. The amount field says Ft whatever the currency (T8).
 */
export const REQUESTS = {
	awaiting: 'Válaszra vár',
	approved: 'Jóváhagyva',
	refused: 'Elutasítva',
	award: 'Jóváhagyva (%s)', // [UNAPPROVED T6]
	answer: 'Megválaszolás',
	change: 'Válasz módosítása',
	// The two buttons' screen-reader names, naming the request: „Megválaszolás: <kérelem>”. [UNAPPROVED T961] [UNAPPROVED T962]
	answerLabel: 'Megválaszolás: %s',
	changeLabel: 'Válasz módosítása: %s',
	// The pointer of a request's field group on „Adatok”: it opens that request on „Kérelmek”. [UNAPPROVED T960]
	goTo: 'Ugrás a kérelemhez',
	dialogTitle: 'Kérelem megválaszolása',
	answerField: 'Válasz',
	answerEmpty: 'Add meg a választ.',
	yes: 'Igen',
	no: 'Nem',
	amountField: 'Megítélt összeg (Ft)',
	noteField: 'Megjegyzés',
	saved: 'Válasz rögzítve.',
	// [UNAPPROVED T10]
	full: 'Ennek a lehetőségnek már minden helye betelt. Ha jóváhagyod a kérelmet, a létszám túllépi a keretet.',
	awardEmpty: 'Add meg a megítélt összeget.',
	noteTooLong: 'A megjegyzés legfeljebb 500 karakter lehet.', // [UNAPPROVED T257], as the confirmation's T45
};

/**
 * The four status acts of the detail panel (S39 on #247). Every visible string is unapproved until the owner ticks
 * its box (T700..T723, the renamed ones T953..T959), except the reused ones: „Mentés” and „Mégse” (MONEY), „Indoklás”
 * (CONFIRM) and the generic failure (MONEY.saveFail). The „full” warning and the server's refusals are shown with
 * the server's own text (the preview's `warnings`, the error's `message`) and are not here.
 */
export const STATUS_ACTS = {
	// The four buttons. [UNAPPROVED T700] [UNAPPROVED T703]; withdraw and remove are T953 and T954
	admit: 'Felvétel a várólistáról',
	withdraw: 'Megjelölés lemondottként', // [UNAPPROVED T953] J1
	remove: 'Megjelölés töröltként', // [UNAPPROVED T954] J2
	restore: 'Jelentkezés visszaállítása',
	// The dialog titles. [UNAPPROVED T704] [UNAPPROVED T707]; withdraw and remove are T955 and T956
	admitTitle: 'Jelentkező felvétele a várólistáról',
	withdrawTitle: 'Megjelölés lemondottként', // [UNAPPROVED T955] J3
	removeTitle: 'Megjelölés töröltként', // [UNAPPROVED T956] J4
	restoreTitle: 'Jelentkezés visszaállítása',
	// What will happen. [UNAPPROVED T708] [UNAPPROVED T710] [UNAPPROVED T711]; withdraw is T957
	admitBody: 'A jelentkezés rögzített állapotba kerül.',
	withdrawBody: 'A jelentkezés lemondott állapotba kerül. A várólistáról senki sem kerül be automatikusan.', // [UNAPPROVED T957] J5 (J6, T710, keeps its word „törölt”)
	removeBody: 'A jelentkezés törölt állapotba kerül. A várólistáról senki sem kerül be automatikusan.',
	restoreBody: 'A jelentkezés rögzített állapotba kerül.',
	// Under the admit dialog's warning. [UNAPPROVED T713]
	serialEnd: 'A felvett jelentkező az utolsó sorszámot kapja.',
	// The snackbars. [UNAPPROVED T714] [UNAPPROVED T717]; withdraw and remove are T958 and T959
	admitted: 'Jelentkező felvéve a várólistáról.',
	withdrawn: 'Jelentkezés lemondottként megjelölve.', // [UNAPPROVED T958] J7
	removed: 'Jelentkezés töröltként megjelölve.', // [UNAPPROVED T959] J8
	restored: 'Jelentkezés visszaállítva.',
	// The money section's heading (the earlier prototype's S16). [UNAPPROVED T718]
	moneyHeading: 'Pénzügyi hatás',
	// Under the money table of withdraw and remove (S23): nothing is paid back by the act. [UNAPPROVED T719]
	noAutomaticRefund: 'Nincs automatikus visszafizetés.',
	// The capacity line of a seat with a limit (S24): the pool's label, then `taken_after` and `limit` from the
	// preview's `seats`. „mentés után” as the money table's „Tartozás mentés után”. [UNAPPROVED T720]
	capacity: '%1$s – foglalt helyek mentés után: %2$d / %3$d',
	// The same line for a seat the server gives no label, so no dash starts it. [UNAPPROVED T723]
	capacityUnlabelled: 'Foglalt helyek mentés után: %1$d / %2$d',
	// The retention sentence under the money table of withdraw and remove, by the preview's `retention`
	// (the earlier prototype's S18 and S17). [UNAPPROVED T721] [UNAPPROVED T722]
	retentionAll: 'Az esemény a befizetett összeget teljes egészében megtartja.',
	retentionNone: 'Az esemény nem tartja meg a befizetett összeget.',
};

/**
 * Staff editing of a registration's field values (B5-2 on #249). Every string is unapproved until the owner ticks
 * its box (T230..T233), except the reused ones: „Mentés” and „Mégse” (MONEY), „Indoklás” and its length message
 * (CONFIRM, its length message), the generic failure (MONEY.saveFail), „Nincs válasz” (GRID.unanswered), „–” (an empty value, MONEY's
 * preview dash) and the „full” warning (the preview's `warnings`, the server's text). Server refusals are shown
 * with the server's own text.
 */
export const FIELDS = {
	// The button of a writable field. [UNAPPROVED T231]
	edit: 'Szerkesztés',
	// The button's screen-reader name, „Szerkesztés: <mező>”. [UNAPPROVED T233]
	editLabel: 'Szerkesztés: %s',
	// The snackbar after a save, also after an answer that changed nothing. [UNAPPROVED T232]
	saved: 'Adat mentve.',
	// An empty value in the list (reused: the preview's dash).
	empty: '–',
};
