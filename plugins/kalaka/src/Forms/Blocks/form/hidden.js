/**
 * Outside a form's editor, where the allowed blocks stay `true`, the gated blocks (the field, page and
 * after-submit blocks no `BlockHost` names there) are not offered: they are unregistered on the client once
 * every editor script has registered its blocks. The names come from the server with the editor's other data
 * (`hidden` in `window.kalakaFormEditor`, read by `server.js`; `Kalaka\Forms\Engine\BlockGate::hiddenHere()`).
 * The server list stays untouched, so a block registered only in JavaScript survives.
 */

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
