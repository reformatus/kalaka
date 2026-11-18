/**
 * One pick of the sum row's counted members: saves it, and shows the sum row it answers only while the
 * picked view's tab is still shown and no later pick has started; a failed save reloads the stored sum row
 * for that view, shown on the same condition of the tab. The tab and the pick are checked again after every
 * await, so a reload that ends after either of them changed does not overwrite the row now on screen. A sum
 * row computed for filters or a search that have changed meanwhile is not shown: the stored one is read
 * again for the ones now on screen.
 *
 * @param {Object}   pick
 * @param {Function} pick.save     saves the pick; resolves the view's new sum row
 * @param {Function} pick.reload   resolves the view's stored sum row, over the filters now on screen
 * @param {Function} pick.apply    shows a sum row
 * @param {Function} pick.revert   forgets the local pick, so the menu shows the stored choice again
 * @param {Function} pick.isShown  whether the picked view's tab is still shown
 * @param {Function} pick.isLatest whether no later pick has started
 * @param {Function} pick.isFresh  whether the filters and search are still the ones the save answered for
 */
export async function runPick( { save, reload, apply, revert, isShown, isLatest, isFresh = () => true } ) {
	try {
		const result = await save();
		if ( ! isShown() || ! isLatest() ) {
			return;
		}
		const shown = isFresh() ? result : await reload();
		if ( isShown() && isLatest() ) {
			apply( shown );
		}
	} catch {
		if ( revert && isShown() ) {
			revert();
		}
		const result = await reload();
		if ( isShown() && isLatest() ) {
			apply( result );
		}
	}
}
