/**
 * The detail panel's small rules, apart from the components so Jest runs them as they are: the tab the viewer
 * last clicked (kept for the browser session). No WordPress imports.
 */

/** The `sessionStorage` key of the last-clicked tab. */
export const TAB_KEY = 'kalaka-panel-tab';

const session = () => {
	try {
		return window.sessionStorage;
	} catch {
		return null;
	}
};

/**
 * The tab to open: the one clicked last in this browser session, or the first when it is not among `names`
 * (the branch has no such tab, or the viewer has no right to it).
 *
 * @param {string[]} names the tabs on screen, in order
 * @param {Object}   store the session storage (the browser's by default)
 */
export function rememberedTab( names, store = session() ) {
	let saved = null;
	try {
		saved = store ? store.getItem( TAB_KEY ) : null;
	} catch {
		saved = null;
	}
	return names.includes( saved ) ? saved : names[ 0 ];
}

/** Keeps the clicked tab for the session; a storage that refuses is ignored. */
export function rememberTab( name, store = session() ) {
	try {
		if ( store ) {
			store.setItem( TAB_KEY, name );
		}
	} catch {
		// Nothing to keep it in: the tab simply is not remembered.
	}
}
