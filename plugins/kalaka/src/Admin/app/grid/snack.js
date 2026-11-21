/**
 * The page's snackbar notices (topic 6a round 3: the copy result is a snackbar). Pure: no WordPress imports.
 * A success goes away by itself; an error needs an explicit dismissal.
 */

/** How long a success snackbar stays. */
export const SNACKBAR_MS = 5000;

/** The notices with one more: `status` is `success` or `error`. */
export const withNotice = ( list, id, text, status ) =>
	list.concat( { id, content: text, status, spokenMessage: text, explicitDismiss: status === 'error' } );

/** The notices without one. */
export const withoutNotice = ( list, id ) => list.filter( ( notice ) => notice.id !== id );
