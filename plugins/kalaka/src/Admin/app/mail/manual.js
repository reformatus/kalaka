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
 * The keys of one page (the dialog is opened and closed on it as often as the admin likes): the same body and
 * selection (the codes, or the view with its filters and search) always give the same key, so a retry after a lost
 * answer, a second click or a closed and reopened dialog is the same request and the server answers the first batch;
 * another body or selection is another request and gets its own key. The levélkeret is the template's own property
 * (walkthrough 8), so it is no input of the send and no part of the key.
 */
export function createKeys( make = newRequestKey ) {
	const keys = new Map();
	return ( bodyId, selection ) => {
		const signature = [ bodyId, selectionSignature( selection ) ].join( '|' );
		if ( ! keys.has( signature ) ) {
			keys.set( signature, make() );
		}
		return keys.get( signature );
	};
}

const pageStores = new Map();

/**
 * The keys of one event for the life of the page load, shared by everything on the page that sends: the grid's action
 * is rebuilt when the tab changes (a grant holder's tabs differ in `kalaka_manual_mail`) and „Levelezés” has one
 * list per event, yet the same body and codes must stay one request. A reload makes new keys: that gap is the
 * server's (the key never expires), see the record's hole 25.
 */
export function pageKeys( event ) {
	if ( ! pageStores.has( event ) ) {
		pageStores.set( event, createKeys() );
	}
	return pageStores.get( event );
}

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
