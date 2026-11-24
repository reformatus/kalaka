/**
 * The status acts of the detail panel (S39 on #247): which acts the read offers, the ability each runs, the
 * reason rule, and what a preview or a refusal turns into. No WordPress imports, so Jest runs it as it is. The
 * UI decides no access and no money: `can.*` is the server's and the preview's `over_capacity` and `seats` are
 * shown as they come. Every choice is an [UNAPPROVED N7xx] box in internal/docs/s39-247-prototype.md.
 */
import { validateReason } from './confirmation';
import { fmt } from './format';
import { formatBalance, formatMoney } from './money';
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

/**
 * Whether the preview is asked as the dialog opens: for all acts but remove, whose preview needs the reason (the
 * server refuses it without one, as it refuses the act), so remove asks once a reason has been typed.
 * [UNAPPROVED N706]
 */
export const previewsAtOpen = ( act ) => act !== 'remove';

/**
 * The warnings of a preview, the server's own text (`warnings`: the "full" text when a pool would go over);
 * nothing is built or copied here. [UNAPPROVED N707]
 */
export const warningsOf = ( preview ) =>
	preview && Array.isArray( preview.warnings ) ? preview.warnings.filter( ( text ) => typeof text === 'string' && text !== '' ) : [];

/**
 * The money section's rows: the preview's `money` per payer, as the server gives it, shown as the panel's money
 * words show amounts. Null (no section) without `money` (admit, a user without money view, a fee that cannot be
 * priced) or with no payer. The payer's name is the read's label, else its key. No retention rule is read or
 * worded here. [UNAPPROVED N714] [UNAPPROVED N715]
 *
 * @param {?Object} preview the act's preview
 * @param {?Object} money   the detail read's `money` (`{ currency, payers }`), for the currency and the labels
 */
export function moneyRowsOf( preview, money ) {
	if ( ! preview || ! Array.isArray( preview.money ) || preview.money.length === 0 ) {
		return null;
	}
	const currency = money && money.currency;
	const payers = money && Array.isArray( money.payers ) ? money.payers : [];
	return preview.money.map( ( row ) => {
		const found = payers.find( ( payer ) => payer.payer === row.payer );
		return {
			key: row.payer,
			payer: found && found.label ? found.label : row.payer,
			paid: formatMoney( row.paid, currency ),
			now: formatBalance( row.balance_now, currency ),
			after: formatBalance( row.balance_after, currency ),
		};
	} );
}

/**
 * The capacity lines: each seat of the preview that has a limit, with its label and `taken` of `limit`. A seat
 * with no limit (null) has no line. `taken` is what the other registrations hold, as the server counts it.
 * [UNAPPROVED N716]
 */
export function seatLinesOf( preview ) {
	return ( preview && Array.isArray( preview.seats ) ? preview.seats : [] )
		.filter( ( seat ) => seat.limit !== null && seat.limit !== undefined )
		.map( ( seat, index ) => ( {
			key: `${ seat.pool }-${ seat.field || '' }-${ index }`,
			label: seat.label || '',
			line: fmt( STATUS_ACTS.capacity, seat.taken, seat.limit ),
		} ) );
}

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
