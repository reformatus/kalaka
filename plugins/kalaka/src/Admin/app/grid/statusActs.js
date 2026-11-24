/**
 * The status acts of the detail panel (S39 on #247): which acts the read offers, the ability each runs, the
 * reason rule, and what a preview or a refusal turns into. No WordPress imports, so Jest runs it as it is. The
 * UI decides no access and no money: `can.*` is the server's and the preview's `over_capacity` and `seats` are
 * shown as they come. Every choice is an [UNAPPROVED N7xx] box in internal/docs/s39-247-prototype.md.
 */
import { validateReason } from './confirmation';
import { MONEY, STATUS_ACTS } from './texts';

/** The four acts, in the order of the buttons. [UNAPPROVED N701] */
export const ACTS = [ 'admit', 'withdraw', 'remove', 'restore' ];

/** The acts to show: exactly those the read's `can` flags. No status logic here. [UNAPPROVED N701] */
export const actsFor = ( can ) => ACTS.filter( ( act ) => !! ( can && can[ act ] === true ) );

/** The ability an act runs (#247). */
export const abilityOf = ( act ) => `kalaka/${ act }-registration`;

/** Whether the reason is required (#247: remove only). [UNAPPROVED N703] */
export const reasonRequired = ( act ) => act === 'remove';

/** The act's input; the reason only when there is one. */
export function inputOf( event, code, reason, preview = false ) {
	const input = { event_id: event, code };
	if ( reason !== '' ) {
		input.reason = reason;
	}
	if ( preview ) {
		input.preview = true;
	}
	return input;
}

/** The reason's message, '' when fine: required for remove, at most 500 characters otherwise. [UNAPPROVED N703] */
export function reasonProblem( act, reason ) {
	if ( reasonRequired( act ) ) {
		return validateReason( reason );
	}
	return String( reason ).trim() === '' ? '' : validateReason( reason );
}

/** Whether the preview for admit and restore is asked: only the acts that take seats again. [UNAPPROVED N706] */
export const takesSeats = ( act ) => act === 'admit' || act === 'restore';

/**
 * Whether the preview says some seat would go over the limit: `over_capacity` (the event's places) or any pool
 * `over`. [UNAPPROVED N707]
 */
export const goesOver = ( preview ) =>
	!! preview && ( preview.over_capacity === true || ( preview.seats || [] ).some( ( seat ) => seat.outcome === 'over' ) );

const BY_ACT = {
	admit: [ 'admit', 'admitTitle', 'admitBody', 'admitted' ],
	withdraw: [ 'withdraw', 'withdrawTitle', 'withdrawBody', 'withdrawn' ],
	remove: [ 'remove', 'removeTitle', 'removeBody', 'removed' ],
	restore: [ 'restore', 'restoreTitle', 'restoreBody', 'restored' ],
};
export const labelOf = ( act ) => STATUS_ACTS[ BY_ACT[ act ][ 0 ] ];
export const titleOf = ( act ) => STATUS_ACTS[ BY_ACT[ act ][ 1 ] ];
export const bodyOf = ( act ) => STATUS_ACTS[ BY_ACT[ act ][ 2 ] ];
export const snackbarOf = ( act ) => STATUS_ACTS[ BY_ACT[ act ][ 3 ] ];

/** The codes whose server text the dialog shows as it is: the typed refusals of #247 and the runner's. [UNAPPROVED N709] */
export const SHOWN_CODES = [
	'kalaka_status_act_refused',
	'kalaka_rule_violation',
	'kalaka_invalid_input',
	'kalaka_not_found',
	'kalaka_permission_denied',
	'kalaka_version_conflict',
];

/** The codes after which the panel behind the dialog is stale, so it re-reads. [UNAPPROVED N711] */
export const STALE_CODES = [ 'kalaka_status_act_refused', 'kalaka_rule_violation', 'kalaka_version_conflict', 'kalaka_not_found' ];

/**
 * What a failed act says: the server's text for a known code, else the generic failure (MONEY.saveFail). Never
 * English or a raw message.
 *
 * @param {?Object} error the rejected `apiFetch` error (`{ code, message, data }`)
 */
export function failureOf( error ) {
	const message = error && error.message ? String( error.message ) : '';
	return error && SHOWN_CODES.includes( error.code ) && message !== '' ? message : MONEY.saveFail;
}

export const staleAfter = ( error ) => !! error && STALE_CODES.includes( error.code );
