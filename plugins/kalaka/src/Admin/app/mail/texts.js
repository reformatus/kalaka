/**
 * The Hungarian text of the mail screens (prototype). Every string is unapproved until the owner ticks its box and
 * none has been through the Hungarian lektor. Approved strings are reused where they fit and are named in their
 * comment: the mail labels, the refusal texts and the pause banner texts come from the server as they are, and the
 * words below that are marked "approved" are the ones the code already ships. `%d`, `%s` and `%1$s` are filled by
 * `fmt()` (grid/format.js). PHP's own text is in `Admin/Mail/Texts.php` ([UNAPPROVED T270, T271]).
 */

/** The event page's four tabs. [UNAPPROVED T272] */
export const TABS = {
	label: 'Levelek',
	mails: 'Levelek',
	bodies: 'Levélsablonok', // approved: the post type's plural
	troubled: 'Sikertelen küldések',
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
};

/** The event's layout (the raw HTML wrapper). [UNAPPROVED T275] */
export const LAYOUT = {
	title: 'Levélkeret', // approved word
	field: 'A levélkeret HTML-je',
	help: 'A {{content}} jelölő helyére kerül a levél szövege.',
};

/** The bodies tab. Status words of a post. [UNAPPROVED T276] */
export const BODIES = {
	subject: 'Tárgy',
	status: 'Állapot', // approved: the grid's
	usedBy: 'Használja',
	modified: 'Módosítva',
	edit: 'Szerkesztés',
	none: 'Nem található levélsablon.', // approved: the post type's
	new: 'Új levélsablon', // approved
	create: 'Létrehozás',
	titleField: 'Tárgy',
	titleEmpty: 'Add meg a tárgyat.',
	status_publish: 'Közzétéve',
	status_draft: 'Piszkozat',
	status_pending: 'Függőben',
	status_private: 'Privát',
	status_future: 'Ütemezve',
	hint: 'A levélsablon szövegét a szerkesztőben lehet megírni.',
};

/** The failed deliveries tab. [UNAPPROVED T277] */
export const TROUBLED = {
	code: 'Kód', // approved
	mail: 'Levél',
	manual: 'Csoportos levél (%d.)',
	status: 'Állapot', // approved
	attempts: 'Kísérletek',
	queued: 'Sorba állítva',
	lastAttempt: 'Utolsó kísérlet',
	error: 'Hiba',
	none: 'Nincs sikertelen küldés.',
	failed: 'Sikertelen',
	pending: 'Hibával várakozik',
	sending: 'Küldés alatt (elakadt)',
	send: 'Levél küldése',
	sendFor: 'Levél küldése: %s',
};

/** The sender page. [UNAPPROVED T278] */
export const SENDER = {
	running: 'A levélküldés fut.',
	pausedManual: 'A levélküldés szünetel', // approved: the banner's title
	pausedErrors: 'A levélküldés hiba miatt szünetel', // approved: the banner's title
	since: 'Szünetel ekkortól',
	by: 'Szüneteltette',
	system: 'Kaláka',
	delivery: 'A hibát jelző levél',
	deliveryOf: '%1$d. küldés, %2$s',
	error: 'A tárolt hiba',
	errorsInRow: 'Egymást követő hibák',
	budget: 'Óránkénti keret',
	queued: 'Küldésre váró levelek',
	unknownEvent: 'Esemény %d.',
};

/** The sender's controls and their confirmations. [UNAPPROVED T279] */
export const CONTROLS = {
	pause: 'Küldés szüneteltetése',
	resume: 'Küldés folytatása',
	clearQueue: 'Küldési sor ürítése',
	clearEvent: 'Az esemény várakozó leveleinek törlése',
	clearQueueConfirm: 'Minden küldésre váró levél törlődik a küldési sorból (%d levél). A már elküldött és a sikertelen levelek megmaradnak.',
	clearEventConfirm: 'Az esemény minden küldésre váró levele törlődik a küldési sorból (%d levél).',
	confirm: 'Törlés',
	paused: 'A küldés szünetel.',
	resumed: 'A küldés folytatódik.',
	alreadyPaused: 'A küldés már szünetelt.',
	notPaused: 'A küldés nem szünetelt.',
	cleared: '%d levél törölve.',
};

/** The per-event counts on the sender page. [UNAPPROVED T280] */
export const COUNTS = {
	event: 'Esemény',
	held: 'Nincs közzétéve',
	published: 'Közzétéve',
	queued: 'Várakozik',
	sent: 'Elküldve',
	failed: 'Sikertelen',
	withError: 'Hibával várakozik',
	stuck: 'Küldés alatt',
	none: 'Még egy eseménynek sincs levele.',
	open: 'Levelek',
};

/** The manual mail dialog. [UNAPPROVED T281] */
export const MANUAL = {
	title: 'Levél küldése',
	loading: 'A címzettek ellenőrzése…',
	previewFail: 'A címzettek ellenőrzése nem sikerült.',
	body: 'Levélsablon', // approved
	pick: '– válassz –',
	noBodies: 'Az eseménynek nincs közzétett levélsablonja.',
	layout: 'Levélkeret használata', // „levélkeret” is approved
	toQueue: '%d jelentkezőnek megy ki a levél.',
	toQueueNone: 'Egy jelentkezőnek sem megy ki a levél.',
	skipped: 'Nem kap levelet:',
	unknown: 'Ismeretlen kód:',
	reason_withdrawn: 'Visszavont', // approved status words
	reason_removed: 'Törölt',
	tooMany: 'Egyszerre legfeljebb 1000 jelentkezőnek küldhető levél.',
	send: 'Küldés',
	sending: 'Küldés…',
	noBody: 'Válassz levélsablont.',
};

/** What a send answers. [UNAPPROVED T282] */
export const RESULT = {
	queued: '%d levél a küldési sorba került.',
	skipped: '%d jelentkező kimaradt:',
	repeat: 'Ez a küldés már megtörtént, újabb levél nem került a sorba.',
	paused: 'A levélküldés szünetel, ezért a levelek a küldési sorban várnak.',
	held: 'Az esemény nincs közzétéve, ezért a levelek a küldési sorban várnak.',
	batch: '%d. csoportos levél',
};

/** The batches tab. [UNAPPROVED T283] */
export const BATCHES = {
	id: 'Szám',
	subject: 'Tárgy',
	created: 'Küldve',
	by: 'Küldte',
	queued: 'Sorba került',
	skipped: 'Kimaradt',
	layout: 'Levélkerettel',
	none: 'Még nem ment ki csoportos levél.',
	members: 'Jelentkezők',
	open: '%d. csoportos levél részletei',
	outcome_queued: 'Sorba került',
	outcome_skipped_withdrawn: 'Kimaradt: Visszavont',
	outcome_skipped_removed: 'Kimaradt: Törölt',
	deleted: 'Törölt levélsablon',
};

/** The grid's bulk action. [UNAPPROVED T284] */
export const GRIDACTION = {
	label: 'Levél küldése',
};

/** Words for a delivery or a batch's user that the server leaves null. [UNAPPROVED T285] */
export const WHO = {
	nobody: 'Kaláka',
};
