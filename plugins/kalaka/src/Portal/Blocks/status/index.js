/**
 * The status block in the editor: a placeholder only; the server renders the visitor's active
 * registration on the page.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { createElement } from '@wordpress/element';
import metadata from './block.json';

const PLACEHOLDER =
	'Jelentkezésem állapota: itt jelenik meg a látogató aktív jelentkezésének állapota.';

registerBlockType( metadata.name, {
	edit: function Edit() {
		return createElement( 'p', useBlockProps(), PLACEHOLDER );
	},
	save: () => null,
} );
