/**
 * The pure logic of the requests block (slice B): what a request's state says, whether the dialog asks for an
 * amount, the one check of the dialog, and the ability's input. No WordPress imports, so Jest runs it as it is.
 * [UNAPPROVED N7]
 */
import { amountString, formatMoney, parseAmount } from './money';
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

/** The dialog's check: an aid "yes" needs an amount above zero; a message, or an empty string when it may be sent. */
export function validateAnswer( item, answer, amount ) {
	if ( ! asksAmount( item, answer ) ) {
		return '';
	}
	if ( String( amount ).trim() === '' ) {
		return REQUESTS.awardEmpty;
	}
	const minor = parseAmount( amount );
	return minor === null || minor <= 0 ? MONEY.amountBad : '';
}

/**
 * A "yes" to an offer is a partner's approval, recorded with its reason: the dialog's note is then required
 * (release-plan.md, "Support and decisions"). A refusal and aid take it optionally. [UNAPPROVED N162]
 */
export const asksReason = ( item, answer ) => item.kind === 'offer' && answer === 'yes';

/**
 * The note's check, on the text that is sent (trimmed): the existing "give the reason" message when a reason is
 * required and empty, else at most 500 characters, counted by character, not by UTF-16 unit, so the field has no
 * `maxLength` of its own (as the confirmation dialog's reason). A message, or an empty string. [UNAPPROVED T257]
 */
export function validateNote( item, answer, note ) {
	const text = String( note ).trim();
	if ( asksReason( item, answer ) && text === '' ) {
		return MONEY.noteRequired;
	}
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
