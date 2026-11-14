/**
 * Outside a form's editor, where the allowed blocks stay `true`, the gated blocks (the field, page and
 * after-submit blocks no `BlockHost` names there) are not offered: they are unregistered on the client once
 * every editor script has registered its blocks (`Kalaka\Forms\Engine\BlockGate::enqueueHiding()`, which
 * prints the names before this script as `window.kalakaHiddenBlocks`). The server list stays untouched, so a block
 * registered only in JavaScript survives.
 *
 * Read when used, never at import, so the rule stays testable on its own.
 */

/** The block names the server hides in this editor; none when it printed nothing. */
export const hiddenBlocks = () => {
	const names = globalThis.kalakaHiddenBlocks;
	return Array.isArray(names) ? names.filter((name) => typeof name === 'string') : [];
};

/**
 * Unregisters each of the names that is registered; a name that is not is left alone.
 *
 * @param {string[]} names
 * @param {{ getBlockType: Function, unregisterBlockType: Function }} blocks
 */
export const hide = (names, { getBlockType, unregisterBlockType }) => {
	names.forEach((name) => {
		if (getBlockType(name)) {
			unregisterBlockType(name);
		}
	});
};
