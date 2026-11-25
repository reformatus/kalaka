/**
 * The manual mail dialog's pure logic: the repeat guard's keys and the words of a result. No WordPress imports.
 */

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
 * The keys of one page (the dialog is opened and closed on it as often as the admin likes): the same body, layout and
 * codes always give the same key, so a retry after a lost answer, a second click or a closed and reopened dialog is
 * the same request and the server answers the first batch; another body, layout or codes is another request and gets
 * its own key. (The server compares the body and the codes of a repeat, never the layout,
 * which is why the layout is part of the key here.)
 */
export function createKeys( make = newRequestKey ) {
	const keys = new Map();
	return ( bodyId, layout, codes ) => {
		const signature = [ bodyId, layout ? 1 : 0, [ ...codes ].sort().join( ',' ) ].join( '|' );
		if ( ! keys.has( signature ) ) {
			keys.set( signature, make() );
		}
		return keys.get( signature );
	};
}

/** Whether a failed preview is the server refusing more codes than one send may hold (core's 400). */
export const isTooMany = ( error ) =>
	!! error && error.code === 'rest_invalid_param' && !! error.data && error.data.status === 400;

/**
 * The two reads that say, before a send, whether sending is paused (the site's sender read) and whether the event is
 * held (the event's mail read), from the preview's own path. Both are full access's: a grant holder's read is refused
 * and the dialog then says nothing about either before the send (the answer still does). [UNAPPROVED N293]
 */
export function stateReads( previewPath ) {
	const event = previewPath.replace( /\/manual\/preview$/, '' );
	return { event, site: event.replace( /\/events\/\d+\/mail$/, '/mail' ) };
}
