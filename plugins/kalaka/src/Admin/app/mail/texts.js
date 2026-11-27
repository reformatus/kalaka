/**
 * The Hungarian text of the mail screens (prototype). Every string is unapproved until the owner ticks its box and
 * none has been through the Hungarian lektor. Approved strings are reused where they fit and are named in their
 * comment: the mail labels, the refusal texts and the pause banner texts come from the server as they are, and the
 * words below that are marked "approved" are the ones the code already ships. `%d`, `%s` and `%1$s` are filled by
 * `fmt()` (grid/format.js). PHP's own text is in `Admin/Mail/Texts.php` ([UNAPPROVED T270, T271]).
 */

/**
 * The event page's three tabs. [UNAPPROVED T272] The failed list is no tab any more: the owner's ruling puts every
 * queue list on „Levelezés” (`SENDER.troubled`). Tab names the lektor questioned (owner's choice, not changed here):
 * „Levelek” (option „Automatikus levelek”) and „Csoportos levelek” (option „Kézzel küldött levelek”).
 */
export const TABS = {
	label: 'Levelek',
	mails: 'Levelek',
	bodies: 'Levélsablonok', // approved: the post type's plural
	batches: 'Csoportos levelek',
};

/** Reads that fail, and the generic fallback of an act with no server text. [UNAPPROVED T273] */
export const COMMON = {
	loading: 'Betöltés…', // approved: the grid's
	loadFail: 'Az oldal betöltése nem sikerült.',
	actFail: 'A művelet nem sikerült. Próbáld újra.',
	save: 'Mentés', // approved
	cancel: 'Mégse', // approved
	close: 'Bezárás', // approved
	saved: 'Mentve.',
	yes: 'Igen', // approved
	no: 'Nem', // approved
	none: '–',
};

/** The „Levelek” tab: one block per action mail. The mail's name is the server's (approved). [UNAPPROVED T274] */
export const MAILS = {
	enabled: 'Bekapcsolva',
	body: 'Levélsablon', // approved: the post type's name
	noBody: '– nincs –',
	layout: 'Levélkeret használata', // „levélkeret” is approved
	held: 'Az esemény nincs közzétéve, ezért a levelek a küldési sorban várnak.', // „küldési sor” is approved
	unsaved: 'Nincs mentve', // beside „Mentés” while the chosen body differs from the saved one [UNAPPROVED T293]
	saveBody: 'Mentés', // approved; the body choice is saved by this button, not on every change [UNAPPROVED T287]
};

/** The event's layout (the raw HTML wrapper). [UNAPPROVED T275] */
export const LAYOUT = {
	title: 'Levélkeret', // approved word
	field: 'A levélkeret HTML-je',
	help: 'A {{content}} jelölő helyére kerül a levélsablon szövege.', // reworded per the lektor [UNAPPROVED T275]
};

/** The bodies tab. Status words of a post. [UNAPPROVED T276] */
export const BODIES = {
	subject: 'Tárgy',
	status: 'Állapot', // approved: the grid's
	usedBy: 'Levél', // reworded per the lektor [UNAPPROVED T276]
	modified: 'Módosítva',
	edit: 'Szerkesztés',
	none: 'Nem található levélsablon.', // approved: the post type's
	new: 'Új levélsablon', // approved
	create: 'Létrehozás',
	titleField: 'Tárgy',
	titleEmpty: 'Add meg a tárgyat.',
	status_publish: 'Elérhető', // the lektor's wording, matching the editor's status
	status_draft: 'Vázlat', // reworded per the lektor (core's word)
	status_pending: 'Függőben',
	status_private: 'Privát',
	status_future: 'Időzítve', // reworded per the lektor (core's word)
	hint: 'A levélsablon szövegét a szerkesztőben lehet megírni.',
	// Two bodies with one subject are told apart by their number. [UNAPPROVED T289]
	numbered: '%1$s (%2$d. számú levélsablon)',
	numberedStatus: '%1$s (%3$s, %2$d. számú levélsablon)',
};

/** The failed deliveries tab. [UNAPPROVED T277] */
export const TROUBLED = {
	code: 'Jelentkező', // the column holds the code and the name beside it
	mail: 'Levél',
	manual: '%d. csoportos levél', // reworded per the lektor
	status: 'Állapot', // approved
	attempts: 'Kísérletek száma', // reworded per the lektor
	queued: 'Sorba állítva',
	lastAttempt: 'Utolsó kísérlet',
	error: 'Hibaüzenet', // reworded per the lektor (pass 2): one word with the sender page's
	none: 'Nincs küldési hiba.', // reworded per the lektor
	failed: 'Sikertelen',
	pending: 'Hiba után újra küldésre vár', // reworded per the lektor
	sending: 'Küldés közben elakadt', // reworded per the lektor
	send: 'Levél küldése',
	sendFor: 'Levél küldése: %s',
};

/** The sender page. [UNAPPROVED T278] */
export const SENDER = {
	state: 'Levélküldés', // the state's heading; the banner's title is not repeated as a heading [UNAPPROVED T291]
	stateLabel: 'Állapot', // approved: the grid's
	troubled: 'Küldési hibák', // reworded per the lektor; the failed list now sits here [UNAPPROVED T272, T290]
	running: 'A levélküldés fut', // reworded per the lektor: no period, like the banner titles
	pausedManual: 'A levélküldés szünetel', // approved: the banner's title
	pausedErrors: 'A levélküldés hiba miatt szünetel', // approved: the banner's title
	since: 'A szünet kezdete', // reworded per the lektor
	by: 'Szüneteltette',
	system: 'Kaláka',
	delivery: 'A hibát jelző levél',
	deliveryOf: '%2$s, %1$d. számú levél', // reworded per the lektor
	error: 'Hibaüzenet', // reworded per the lektor
	errorsInRow: 'Egymást követő hibák száma', // reworded per the lektor
	budget: 'Óránként legfeljebb küldhető levél', // reworded per the lektor: no „keret” for the limit
	budgetDefault: 'Alapértelmezés: %d', // under the budget field, a line of its own [UNAPPROVED T901]
	budgetDefaultInline: 'alapértelmezés: %d', // inside the parentheses of the read-only value [UNAPPROVED T905]
	queued: 'Küldésre váró levelek',
	unknownEvent: '%d. számú esemény', // reworded per the lektor
};

/** The sender's controls and their confirmations. [UNAPPROVED T279] */
export const CONTROLS = {
	pause: 'Levélküldés szüneteltetése', // reworded per the lektor (pass 2), as the ability's label
	resume: 'Levélküldés folytatása',
	clearQueue: 'Küldési sor ürítése',
	clearEvent: 'Az esemény küldésre váró leveleinek törlése', // reworded per the lektor
	clearEventFor: '%1$s: az esemény küldésre váró leveleinek törlése', // the button's accessible name [UNAPPROVED T288]
	clearQueueConfirm: 'Minden küldésre váró levél törlődik a küldési sorból (%d levél). A már elküldött és a sikertelen levelek megmaradnak.',
	clearEventConfirm: '%2$s: az esemény minden küldésre váró levele törlődik a küldési sorból (%1$d levél).', // names the event; reworded per the lektor (pass 2): no „X eseményének” [UNAPPROVED T288]
	confirm: 'Törlés',
	paused: 'A levélküldés szünetel.', // reworded per the lektor (the four sentences)
	resumed: 'A levélküldés folytatódik.',
	alreadyPaused: 'A levélküldés már szünetel.',
	notPaused: 'A levélküldés nem szünetel.',
	cleared: '%d levél törölve.',
	budgetInvalid: 'Az óránként legfeljebb küldhető levelek száma csak 1 és 2000 közötti egész szám lehet.', // the refusal `kalaka_mail_budget_invalid`, shown by its code [UNAPPROVED T902]
	budgetSaved: 'Mentve: óránként legfeljebb %d levél küldhető.', // the snackbar after the budget is saved [UNAPPROVED T900]
};

/** The per-event counts on the sender page. [UNAPPROVED T280] */
export const COUNTS = {
	section: 'Események', // reworded per the lektor: the plural over the table
	event: 'Esemény',
	held: 'Nincs közzétéve',
	published: 'Közzétéve',
	publication: 'Közzététel', // reworded per the lektor: the column no longer equals one of its values
	queued: 'Küldésre vár', // reworded per the lektor: one word for a queued mail
	sent: 'Elküldve',
	failed: 'Sikertelen',
	// The mails that wait after an error are among the ones counted as waiting, not a separate count. [UNAPPROVED T280]
	withError: 'A küldésre várók közül hiba után vár', // reworded per the lektor (pass 2): names its base, as the column is no longer next to „Küldésre vár”
	stuck: 'Küldés közben elakadt', // reworded per the lektor
	none: 'Még egy esemény levele sem került a küldési sorba.', // reworded per the lektor
	open: 'Levelek',
};

/** The manual mail dialog. [UNAPPROVED T281] */
export const MANUAL = {
	title: 'Levél küldése',
	loading: 'A címzettek ellenőrzése…',
	previewFail: 'A címzettek ellenőrzése nem sikerült.',
	body: 'Levélsablon', // approved
	pick: '– válassz –',
	noBodies: 'Az eseménynek nincs elérhető levélsablonja.', // the lektor's wording
	layout: 'Levélkeret használata', // „levélkeret” is approved
	toQueue: 'A levelet %d jelentkező kapja meg.', // reworded per the lektor
	toQueueNone: 'A levelet egy jelentkező sem kapja meg.', // reworded per the lektor
	// The ones who get no mail are told as per-status totals; their list is in a popup of its own, opened by a text button
	// whose label never changes. The totals read „Kihagyva: 1 törölt, 1 lemondott” (a count and the
	// lowercase status word below), the popup lists „00A1 · Kiss Anna – lemondott”.
	// Lektor next.
	skippedTotals: 'Kihagyva: %s',
	skippedOpen: 'Kihagyottak listája',
	skippedTitle: 'Kihagyott jelentkezők',
	unknown: 'Ismeretlen kód:',
	reason_withdrawn: 'lemondott', // status words, lowercase after a code; „lemondott” per the owner's rename (lektor 1, J9)
	reason_removed: 'törölt',
	tooMany: 'Egyszerre legfeljebb 1000 jelentkezőnek küldhető levél.',
	send: 'Küldés',
	sending: 'Küldés…',
	noBody: 'Válassz levélsablont.',
};

/** What a send answers. [UNAPPROVED T282] */
export const RESULT = {
	queued: '%d levél a küldési sorba került.',
	// Reworded per the lektor. On a repeat this replaces the „%d levél a küldési sorba került.” line too.
	repeat: 'Ez a levél korábban már a küldési sorba került (%d levél), ezért újabb levél nem került a sorba.',
	paused: 'A levélküldés szünetel, ezért a levelek a küldési sorban várnak.',
	held: 'Az esemény nincs közzétéve, ezért a levelek a küldési sorban várnak.',
	batch: '%d. csoportos levél',
};

/** The batches tab. [UNAPPROVED T283] */
export const BATCHES = {
	id: 'Sorszám', // reworded per the lektor
	subject: 'Tárgy',
	created: 'Sorba állítva', // reworded per the lektor: the batch's creation time, not a delivery
	by: 'Küldte',
	queued: 'Sorba került',
	skipped: 'Kihagyva',
	layout: 'Levélkerettel',
	none: 'Még nincs csoportos levél.', // reworded per the lektor
	// The member list's disclosure button on a batch's card; the label never changes.
	members: 'Jelentkezők',
	open: '%d. csoportos levél részletei',
	// After a code, lowercase, as the manual mail dialog writes it („16F5 – lemondott”); reworded per the lektor (pass 2).
	outcome_queued: 'sorba került',
	outcome_skipped_withdrawn: 'kihagyva: lemondott', // the owner's rename (lektor 1, J11)
	outcome_skipped_removed: 'kihagyva: törölt',
	gone: '(törölt levélsablon)', // the subject of a batch whose body is trashed or missing [UNAPPROVED T294]
};

/** The grid's bulk action. [UNAPPROVED T284] */
export const GRIDACTION = {
	label: 'Levél küldése',
	selectRow: 'Jelentkezés kijelölése: %s', // the row checkbox's accessible name [UNAPPROVED T286]
};

/** Words for a delivery or a batch's user that the server leaves null. [UNAPPROVED T285] */
export const WHO = {
	nobody: 'Kaláka',
};
