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

/**
 * The act's input; the reason only when there is one. A preview carries no reason: the server takes none on a
 * preview, remove's included. [UNAPPROVED N706]
 */
export function inputOf( event, code, reason, preview = false ) {
	const input = { event_id: event, code };
	if ( preview ) {
		input.preview = true;
	} else if ( reason !== '' ) {
		input.reason = reason;
	}
	return input;
}

/** The reason's message, '' when fine: no act requires a reason (J18), at most 500 characters. [UNAPPROVED N703] */
export const reasonProblem = ( act, reason ) => validateReason( reason );

/**
 * The warnings of a preview, the server's own text (`warnings`: the "full" text when a pool would go over);
 * nothing is built or copied here. [UNAPPROVED N707]
 */
export const warningsOf = ( preview ) =>
	preview && Array.isArray( preview.warnings ) ? preview.warnings.filter( ( text ) => typeof text === 'string' && text !== '' ) : [];

/**
 * The money section's rows: the preview's `money` per payer, as the server gives it, shown as the panel's money
 * words show amounts. Null (no section) without `money` (admit; `money_hidden` true: no money view) or with no
 * payer; `money` null with `money_hidden` false (the fee cannot be priced) shows nothing new: a hole. The payer's
 * name is the read's label, else its key. [UNAPPROVED N714] [UNAPPROVED N715]
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
 * The capacity lines: each seat of the preview that has a limit, as „<label> – foglalt helyek mentés után:
 * taken_after / limit” (no label: „Foglalt helyek mentés után: …”, T723). `taken_after` is the server's count once the act is done (a taking act: `taken` + 1, so
 * 6 / 6 becomes 7 / 6; a leaving act's `released` seats: `taken`). A seat with no limit (null) has no line. The
 * figures are the server's; nothing is computed here. [UNAPPROVED N716] [UNAPPROVED N723]
 */
export function seatLinesOf( preview ) {
	return ( preview && Array.isArray( preview.seats ) ? preview.seats : [] )
		.filter( ( seat ) => seat.limit !== null && seat.limit !== undefined )
		.map( ( seat, index ) => ( {
			key: `${ seat.pool }-${ seat.field || '' }-${ index }`,
			line: seat.label
				? fmt( STATUS_ACTS.capacity, seat.label, seat.taken_after, seat.limit )
				: fmt( STATUS_ACTS.capacityUnlabelled, seat.taken_after, seat.limit ),
		} ) );
}

/**
 * The retention sentence of a leaving act's money section: the preview's `retention` (`everything_paid` or
 * `nothing`) and nothing else; '' for any other value (admit, restore, no money view: the server sends none).
 * [UNAPPROVED N717]
 */
export function retentionLineOf( preview ) {
	switch ( preview && preview.retention ) {
		case 'everything_paid':
			return STATUS_ACTS.retentionAll;
		case 'nothing':
			return STATUS_ACTS.retentionNone;
		default:
			return '';
	}
}

/**
 * What a screen reader hears once the preview has answered (a polite live region): the capacity lines, then each
 * payer's figures with their words, then the retention and refund sentences, as the dialog shows them, each ended
 * with a full stop when it has none. '' while there is nothing. The warnings are not here: the notice speaks its
 * own text (WordPress's `speak`), and the notices are outside the description, so they are heard once.
 * The payer's sentence „Fizető: <név>, Befizetett …, Jelenlegi tartozás …, Tartozás mentés után …” is a composed
 * pattern of its own. [UNAPPROVED N722] [UNAPPROVED T724]
 */
export function spokenOf( act, { seats, rows, retention } ) {
	const end = ( text ) => ( /[.!?]$/.test( text ) ? text : `${ text }.` );
	const parts = seats.map( ( seat ) => seat.line );
	if ( rows ) {
		parts.push( STATUS_ACTS.moneyHeading );
		rows.forEach( ( row ) =>
			parts.push(
				// [UNAPPROVED T724]
				`${ MONEY.payer }: ${ row.payer }, ${ MONEY.paid } ${ row.paid }, ${ MONEY.balanceNow } ${ row.now }, ${ MONEY.balanceAfter } ${ row.after }`
			)
		);
		if ( retention ) {
			parts.push( retention );
		}
		if ( showsRefundLine( act ) ) {
			parts.push( STATUS_ACTS.noAutomaticRefund );
		}
	}
	return parts.length ? parts.map( end ).join( ' ' ) : '';
}

/** Whether the money section's „no automatic refund” line shows: for the leaving acts only. [UNAPPROVED N718] */
export const showsRefundLine = ( act ) => act === 'withdraw' || act === 'remove';

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

/** The codes after which the panel behind the dialog is stale, so it re-reads. [UNAPPROVED N710] */
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
