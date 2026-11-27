/**
 * The pure logic of the requests block (slice B): what a request's state says, whether the dialog asks for an
 * amount, the one check of the dialog, and the ability's input. No WordPress imports, so Jest runs it as it is.
 * [UNAPPROVED N7]
 */
import { amountString, formatMoney, parseAmount, toMinor } from './money';
import { MONEY, REQUESTS } from './texts';

/** The longest note the server takes, in characters. */
export const NOTE_MAX = 500;

/** The requests of a read: `{ currency, items }`, or an empty list when the read does not carry them. */
export const itemsOf = ( read ) => ( ( read && read.requests && read.requests.items ) || [] );

/** The state line of a request: „Válaszra vár”, „Jóváhagyva”, „Elutasítva”, or the award of a granted aid. */
export function stateText( item, currency ) {
	if ( item.state === 'awaiting' ) {
		return REQUESTS.awaiting;
	}
	if ( item.state === 'approved' ) {
		return item.kind === 'aid' && item.amount !== null
			? REQUESTS.award.replace( '%s', formatMoney( item.amount, currency ) )
			: REQUESTS.approved;
	}
	return REQUESTS.refused;
}

/** An aid "yes" is an award: the dialog asks for its amount. An offer, or a "no", takes none. */
export const asksAmount = ( item, answer ) => item.kind === 'aid' && answer === 'yes';

/**
 * The warning before a "yes" that would go over the limit: a refused offer whose pool is full now. The server
 * says so (`seat_full`); the answer may still be given.
 */
export const warnsFull = ( item, answer ) => !! item.seat_full && answer === 'yes';

/**
 * The aid figures the dialog shows (owner 2026-11-27, walkthrough 6.8): the read's own amounts, labelled; empty
 * when the read carries none (a holder whose view/grant gives no money fields). [UNAPPROVED T1134]
 */
export function aidFigures( item ) {
	const aid = item && item.kind === 'aid' ? item.aid : null;
	if ( ! aid ) {
		return [];
	}
	return [ 'full_price', 'can_pay', 'asked', 'max_award' ]
		.filter( ( key ) => aid[ key ] !== null && aid[ key ] !== undefined )
		.map( ( key ) => ( { key, label: REQUESTS.figures[ key ], value: aid[ key ] } ) );
}

/** The aid's `asked` amount, hinted in the dialog's field: „12000”, or '' when the read carries none. */
export function hintedAmount( item ) {
	const minor = item && item.aid && item.aid.asked ? toMinor( item.aid.asked ) : null;
	return minor !== null && minor > 0 ? amountString( minor ) : '';
}

/**
 * The dialog's check: an aid "yes" needs an amount above zero and no more than the read's `max_award`, the full
 * price the act refuses above (`AidAbovePrice`, the same sentence); a message, or an empty string when it may be
 * sent. A holder without the aid figures gets the amount checks only, and the act still refuses. [UNAPPROVED T1134]
 */
export function validateAnswer( item, answer, amount ) {
	if ( ! asksAmount( item, answer ) ) {
		return '';
	}
	if ( String( amount ).trim() === '' ) {
		return REQUESTS.awardEmpty;
	}
	const minor = parseAmount( amount );
	if ( minor === null || minor <= 0 ) {
		return MONEY.amountBad;
	}
	const max = item.aid && item.aid.max_award ? toMinor( item.aid.max_award ) : null;
	return max !== null && minor > max ? REQUESTS.aidAbovePrice : '';
}

/**
 * A "yes" to an offer is a partner's approval, so the dialog labels its note „Indoklás” (release-plan.md, "Support
 * and decisions"); the label only names it, a reason is never required (owner 2026-11-26: N985, R230).
 * [UNAPPROVED T8] (the label)
 */
export const asksReason = ( item, answer ) => item.kind === 'offer' && answer === 'yes';

/**
 * The note's check, on the text that is sent (trimmed): at most 500 characters, counted by character, not by UTF-16 unit, so the field has no
 * `maxLength` of its own (as the confirmation dialog's reason). A message, or an empty string. [UNAPPROVED T257]
 */
export function validateNote( item, answer, note ) {
	const text = String( note ).trim();
	return Array.from( text ).length > NOTE_MAX ? REQUESTS.noteTooLong : '';
}

/** The input of `kalaka/answer-request`: the note only when it has text, the amount only for an aid "yes". */
export function buildAnswer( event, code, item, answer, amount, note ) {
	const input = { event_id: event, code, request: item.id, answer };
	if ( asksAmount( item, answer ) ) {
		input.amount = amountString( parseAmount( amount ) );
	}
	if ( String( note ).trim() !== '' ) {
		input.note = String( note ).trim();
	}
	return input;
}

/** The snackbar after a save: none when the act changed nothing (an identical repeat), so it never claims a save. */
export const savedText = ( result ) => ( result && result.changed === false ? '' : REQUESTS.saved );
