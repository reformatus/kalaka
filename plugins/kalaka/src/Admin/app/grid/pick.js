/**
 * One pick of the sum row's counted members: saves it, and shows the sum row it answers only while the
 * picked view's tab is still shown and no later pick has started; a failed save reloads the stored sum row
 * for that view, shown on the same condition of the tab.
 *
 * @param {Object}   pick
 * @param {Function} pick.save     saves the pick; resolves the view's new sum row
 * @param {Function} pick.reload   resolves the view's stored sum row
 * @param {Function} pick.apply    shows a sum row
 * @param {Function} pick.isShown  whether the picked view's tab is still shown
 * @param {Function} pick.isLatest whether no later pick has started
 */
export async function runPick( { save, reload, apply, isShown, isLatest } ) {
	try {
		const result = await save();
		if ( isShown() && isLatest() ) {
			apply( result );
		}
	} catch {
		const result = await reload();
		if ( isShown() ) {
			apply( result );
		}
	}
}
