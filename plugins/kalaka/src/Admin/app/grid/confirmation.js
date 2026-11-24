/**
 * The manual confirmation's pure logic (S20): the confirmation decision of a read, the icon of its state, and
 * the dialog's one check. No WordPress imports, so Jest runs it as it is. [UNAPPROVED N30]
 */
import { CONFIRM } from './texts';

/** The kind of the confirmation decision, as the read declares it. */
export const CONFIRMATION_KIND = 'confirmation';

/** The icon and tone of each state, like the grid's decision mark; an unknown state gets the grid's marker. [UNAPPROVED N30] */
const MARK = {
	confirmed: [ 'dashicons-yes-alt', 'kg-ok' ],
	unconfirmed: [ 'dashicons-marker', '' ],
};

/**
 * The confirmation decision of the read's `registration`: `{ kind, label, state, state_label, effective_at }`,
 * or null when the read has none (the view lists no such column, or nothing is decided yet).
 */
export function confirmationOf( registration ) {
	const found = ( ( registration && registration.decisions ) || [] ).find( ( d ) => d.kind === CONFIRMATION_KIND );
	return found || null;
}

/** The icon class and tone class of a state. */
export const markOf = ( state ) => MARK[ state ] || [ 'dashicons-marker', '' ];

/** The longest reason the server takes (`kalaka/set-manual-confirmation` and `kalaka/unconfirm`), in characters. */
export const REASON_MAX = 500;

/**
 * The dialog's checks, on the text that is sent (trimmed): the reason is required and at most 500 characters
 * (counted as the server counts them, by character, not by UTF-16 unit); a message, or an empty string when it may
 * be sent. The field itself has no length limit: its own `maxLength` would count UTF-16 units, not characters.
 * [UNAPPROVED N73] [UNAPPROVED T45]
 */
export function validateReason( reason ) {
	const text = String( reason ).trim();
	if ( text === '' ) {
		return CONFIRM.reasonEmpty;
	}
	return Array.from( text ).length > REASON_MAX ? CONFIRM.reasonTooLong : '';
}

/**
 * The buttons of the manual confirmation block: `confirm` (the manual flag) with `can.confirm_manually` while the
 * flag is not set (also when the read does not carry it, which is only under the money gate: the set is
 * idempotent); `unconfirm` only with `can.unconfirm`, whatever the flag says. No status logic here: the server's
 * `can` decides alone. So a registration already confirmed by payments shows both buttons ([UNAPPROVED N75]), and a
 * flag on a registration that is not confirmed has no button that revokes it ([UNAPPROVED N70]). [UNAPPROVED N33]
 * [UNAPPROVED N34]
 */
export function manualActions( manual, can ) {
	const actions = [];
	if ( can && can.confirm_manually && ! manual ) {
		actions.push( 'confirm' );
	}
	if ( can && can.unconfirm ) {
		actions.push( 'unconfirm' );
	}
	return actions;
}

/**
 * The warning before the unconfirm: the server's own text from `registration.unconfirm_warning` (#213's T5a, T5b,
 * T5c), only while `can.unconfirm` is true and the text is not null. The panel adds no wording and no rule of its
 * own: whether the rules would confirm again at once is the server's call, so `registration.unconfirm_reconfirms`
 * is not read. [UNAPPROVED N71] [UNAPPROVED N76] The text is as old as the panel's last read, not as the moment the
 * dialog opens ([UNAPPROVED N79]), and a holder without the money view gets the server's text for that case, which
 * was not seen in a browser ([UNAPPROVED N81]).
 */
export function warningOf( registration, can ) {
	const text = registration && registration.unconfirm_warning;
	return can && can.unconfirm && typeof text === 'string' && text !== '' ? text : null;
}

/**
 * Whether the re-read shows the registration confirmed: `registration.confirmed`, which every detail read carries
 * (#213). Null only when the field is absent (an old server).
 */
function confirmedIn( detail ) {
	const confirmed = detail.registration && detail.registration.confirmed;
	return typeof confirmed === 'boolean' ? confirmed : null;
}

/**
 * The snackbar after a save, from what the server answered and the re-read detail (null when the re-read failed:
 * the panel's load-error message shows, and no snackbar claims a state that was not read). `changed: false` wrote
 * nothing, so the panel only shows what is now. A set says it is saved. An unconfirm says it was revoked; if the
 * re-read shows the registration confirmed again (the rules re-confirmed at once), it says that instead; if the
 * re-read cannot tell, there is no snackbar. Nothing here says whether the server sent the confirmation mail
 * again ([UNAPPROVED N80]). [UNAPPROVED N72] [UNAPPROVED T46]
 *
 * @param {boolean}  unconfirm the act was the unconfirm (else the set)
 * @param {?Object}  result    the ability's answer: { changed }
 * @param {?Object}  detail    the re-read detail, null when the re-read failed
 * @return {?string} the snackbar text, or null for none
 */
export function snackbarAfter( unconfirm, result, detail ) {
	if ( ! detail || ( result && result.changed === false ) ) {
		return null;
	}
	if ( ! unconfirm ) {
		return CONFIRM.saved;
	}
	const confirmed = confirmedIn( detail );
	if ( confirmed === null ) {
		return null;
	}
	return confirmed ? CONFIRM.unconfirmedReconfirmed : CONFIRM.unconfirmed;
}
