/**
 * The money table of a mail in the editor: a placeholder only; the server renders the registration's money
 * table when the mail is sent.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { createElement } from '@wordpress/element';
import metadata from './block.json';

const PLACEHOLDER =
	'Pénzügyek táblázata: a levél elküldésekor itt jelenik meg a jelentkezés fizetendő és befizetett összege.';

registerBlockType( metadata.name, {
	edit: function Edit() {
		return createElement( 'p', useBlockProps(), PLACEHOLDER );
	},
	save: () => null,
} );
