/**
 * The detail panel's small rules, apart from the components so Jest runs them as they are: the tab the viewer
 * last clicked (kept for the browser session) and where focus goes after a save. No WordPress imports.
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

/**
 * The tab panel's memory, written only by the viewer's own choice ([UNAPPROVED N1264]): wp-components' `TabPanel`
 * calls `onSelect` when it opens on its initial tab as well, so the opening call names the tab already shown and
 * writes nothing. A remembered tab this branch lacks falls back to the first without overwriting the memory.
 *
 * @param {string[]} names the tabs on screen, in order
 * @param {Object}   store the session storage (the browser's by default)
 * @return {{initial: string, onSelect: Function}} the tab to open, and the handler for `TabPanel`'s `onSelect`
 */
export function tabClicks( names, store = session() ) {
	const initial = rememberedTab( names, store );
	let shown = initial;
	return {
		initial,
		onSelect( name ) {
			if ( name === shown ) {
				return;
			}
			shown = name;
			rememberTab( name, store );
		},
	};
}

/**
 * After a save, focus returns to the control that opened the dialog; when that control is gone, to the panel's
 * close button. Returns the element focused, or null when neither can take it.
 *
 * @param {?Element} opener   the control that opened the dialog
 * @param {?Element} fallback the panel's close button
 */
export function returnFocus( opener, fallback ) {
	const target = opener && opener.isConnected ? opener : fallback;
	if ( target && target.isConnected ) {
		target.focus();
		return target;
	}
	return null;
}
