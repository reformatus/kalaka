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

/** The dialog's check: the reason is required; a message, or an empty string when it may be sent. */
export function validateReason( reason ) {
	return String( reason ).trim() === '' ? CONFIRM.reasonEmpty : '';
}

/**
 * The buttons of the manual confirmation block: `confirm` (the manual flag) with `can.confirm_manually` while the
 * flag is not set (also when the read does not carry it, which is only under the money gate: the ability is
 * idempotent); `unconfirm` only with `can.unconfirm`, whatever the flag says. [UNAPPROVED N33] [UNAPPROVED N34]
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
 * What a failed save says: the server's own text for a refused unconfirm (a 422: payments reach the threshold, or
 * it was confirmed because nothing was due), else null for the dialog's general failure text.
 */
export function refusalOf( error ) {
	return error && error.data && error.data.status === 422 && error.message ? String( error.message ) : null;
}
