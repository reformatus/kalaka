/**
 * The manual mail dialog's pure logic: the repeat guard's keys and the words of a result. No WordPress imports.
 */

import { selectionSignature } from '../grid/selection';

const ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

/** A fresh request key: `[A-Za-z0-9_-]{8,64}`, 24 characters. */
export function newRequestKey( random = Math.random ) {
	let key = '';
	for ( let i = 0; i < 24; i++ ) {
		key += ALPHABET[ Math.floor( random() * ALPHABET.length ) ];
	}
	return key;
}

/**
 * The request key of a send attempt. A key names one attempt: it is made when a send starts (the Küldés press) and
 * is reused only while that attempt has no answer (a double press while the request runs, a retry after a network
 * failure or a server error, also from a reopened dialog), so the server answers the first batch and nothing is
 * queued twice. `spend` ends the attempt: a success or a server refusal spends the key, and the next send, the
 * same body to the same selection included, gets a fresh one. An edit between attempts (another body or
 * selection) is another request and also gets a fresh key. The levélkeret is the template's own property
 * (walkthrough 8), so it is no input of the send and no part of the key.
 *
 * `keyFor( bodyId, selection )` answers the pending attempt's key, or makes a new one; `spend( key )` ends it.
 */
export function createKeys( make = newRequestKey ) {
	let pending = null;
	return {
		keyFor( bodyId, selection ) {
			const signature = [ bodyId, selectionSignature( selection ) ].join( '|' );
			if ( ! pending || pending.signature !== signature ) {
				pending = { signature, key: make() };
			}
			return pending.key;
		},
		spend( key ) {
			if ( pending && pending.key === key ) {
				pending = null;
			}
		},
	};
}

const pageStores = new Map();

/**
 * The keys of one event for the life of the page load, shared by everything on the page that sends: the grid's action
 * is rebuilt when the tab changes (a grant holder's tabs differ in `kalaka_manual_mail`) and „Levelezés” has one
 * list per event, yet an attempt without an answer stays one request wherever it is retried. A reload makes new keys:
 * that gap is the server's (the key never expires), see the record's hole 25.
 */
export function pageKeys( event ) {
	if ( ! pageStores.has( event ) ) {
		pageStores.set( event, createKeys() );
	}
	return pageStores.get( event );
}

/**
 * Whether a failed send is the server's refusal (a 4xx answer): it stores nothing, so the attempt is over. No answer
 * at all (the network) and a server error leave the outcome unknown, so the key is kept for the retry.
 */
export const isRefusal = ( error ) =>
	!! error && !! error.data && Number.isInteger( error.data.status ) && error.data.status >= 400 && error.data.status < 500;

/** Whether a failed preview is the server refusing more codes than one send may hold (core's 400). */
export const isTooMany = ( error ) =>
	!! error && error.code === 'rest_invalid_param' && !! error.data && error.data.status === 400;

/**
 * The two reads that say, before a send, whether sending is paused (the site's sender read) and whether the event is
 * held (the event's mail read), from the preview's own path. Both are full access's: a grant holder's read is refused
 * and the dialog then says nothing about either before the send (the answer still does).
 */
export function stateReads( previewPath ) {
	const event = previewPath.replace( /\/manual\/preview$/, '' );
	return { event, site: event.replace( /\/events\/\d+\/mail$/, '/mail' ) };
}
