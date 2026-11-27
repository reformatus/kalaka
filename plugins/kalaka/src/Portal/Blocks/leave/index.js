/**
 * The leave block in the editor: a placeholder only, the same shape the status and transfer blocks use;
 * the server renders the button for a visitor who has an active registration or is logged in.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { createElement } from '@wordpress/element';
import metadata from './block.json';

const PLACEHOLDER =
	'Kilépés: itt jelenik meg a Kilépés gomb, ha a látogató be van lépve, vagy van aktív jelentkezése.';

registerBlockType( metadata.name, {
	edit: function Edit() {
		return createElement( 'p', useBlockProps(), PLACEHOLDER );
	},
	save: () => null,
} );
