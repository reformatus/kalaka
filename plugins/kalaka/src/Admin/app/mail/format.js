/**
 * The mail screens' pure helpers (no WordPress imports, so Jest runs them as they are).
 */
import { formatMoment } from '../grid/money';
import { BODIES, BATCHES, TROUBLED } from './texts';

/** The status word of a body post; an unknown status as it is. [UNAPPROVED T276] */
export const bodyStatus = ( status ) => BODIES[ 'status_' + status ] || status;

/** The word of a delivery's status. [UNAPPROVED T277] */
export const deliveryStatus = ( status ) => TROUBLED[ status ] || status;

/** The word of a batch member's outcome. [UNAPPROVED T283] */
export const outcomeWord = ( outcome ) => BATCHES[ 'outcome_' + outcome ] || outcome;

/**
 * The text an act's failure shows: the server's own text when it sent one (the typed refusals carry the approved
 * Hungarian text), else the fallback. A failed network request has a message too, in the browser's language, so
 * only an error that has a `code` counts as the server's. Core's own codes (`rest_invalid_param` and the like) have
 * English texts, so only `kalaka_`-prefixed codes and those with a Hungarian-looking text are shown.
 */
export function problemText( error, fallback ) {
	if ( error && typeof error.code === 'string' && error.code.startsWith( 'kalaka_' ) && error.message ) {
		return error.message;
	}
	if ( error && error.data && error.data.kalaka_message ) {
		return error.data.kalaka_message;
	}
	return fallback;
}

/**
 * A server instant as the site's day and time, or „–” when it is no real time. A body that was never dated reads as
 * year -1 (a server gap, reported), and one bad value must not break the whole table.
 */
export function moment( instant, zone ) {
	const time = instant ? new Date( instant ).getTime() : NaN;
	if ( Number.isNaN( time ) || time < 0 ) {
		return '–';
	}
	return formatMoment( instant, zone );
}
