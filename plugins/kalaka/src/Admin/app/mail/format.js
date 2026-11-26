/**
 * The mail screens' pure helpers (no WordPress imports, so Jest runs them as they are).
 */
import { formatMoment } from '../grid/money';
import { fmt } from '../grid/format';
import { BODIES, BATCHES, TROUBLED } from './texts';

/**
 * A batch's subject; a trashed or missing body reads as a null subject with `body_gone`, and the server sends no text of
 * its own (Backend N567), so the word is ours. [UNAPPROVED T294]
 */
export const batchSubject = ( batch ) => ( batch.body_gone || batch.subject === null || batch.subject === undefined ? BATCHES.gone : batch.subject );

/** The status word of a body post; an unknown status as it is. [UNAPPROVED T276] */
export const bodyStatus = ( status ) => BODIES[ 'status_' + status ] || status;

/**
 * A body's text in a select: the subject, with its status in lowercase words when it is not published, and its number
 * when another body of the list has the same subject, so two of them are never the same option. Bodies without a
 * `status` (the manual mail's preview lists published ones only) carry no status word. [UNAPPROVED T289]
 */
export function bodyLabel( body, bodies ) {
	const twin = bodies.some( ( other ) => other.id !== body.id && other.subject === body.subject );
	const word = body.status && body.status !== 'publish' ? bodyStatus( body.status ).toLowerCase() : null;
	if ( twin && word ) {
		return fmt( BODIES.numberedStatus, body.subject, body.id, word );
	}
	if ( twin ) {
		return fmt( BODIES.numbered, body.subject, body.id );
	}
	return word ? `${ body.subject } (${ word })` : body.subject;
}

/** The word of a delivery's status. [UNAPPROVED T277] */
export const deliveryStatus = ( status ) => TROUBLED[ status ] || status;

/** The word of a batch member's outcome. [UNAPPROVED T283] */
export const outcomeWord = ( outcome ) => BATCHES[ 'outcome_' + outcome ] || outcome;

/**
 * The text an act's failure shows: the server's own text when it sent one (the typed refusals carry the approved
 * Hungarian text), else the fallback. A failed network request has a message too, in the browser's language, so
 * only an error that has a `code` counts as the server's. Core's own codes (`rest_invalid_param` and the like) have
 * English texts, so the error's own `message` is shown only for a `kalaka_`-prefixed code; any other error shows the
 * text of its `data.kalaka_message` when it has one, else the fallback. [UNAPPROVED N298]
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
 * A server instant as the site's day and time, or „–” when the read has none: a zero or unset date is `null` in
 * every mail read (the server's rule).
 */
export function moment( instant, zone ) {
	return instant ? formatMoment( instant, zone ) : '–';
}
