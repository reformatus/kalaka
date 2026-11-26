/**
 * The money table of a mail in the editor: a placeholder only; the server renders the registration's money
 * table when the mail is sent.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { createElement } from '@wordpress/element';
import metadata from './block.json';

// [UNAPPROVED T3]
// The wording is the lektor's suggestion.
const PLACEHOLDER =
	'Pénzügyek táblázata: a levél elküldésekor itt jelenik meg a jelentkezés fizetendő összege, ' +
	'befizetései és visszafizetései, valamint a még fizetendő összeg.';

registerBlockType( metadata.name, {
	edit: function Edit() {
		return createElement( 'p', useBlockProps(), PLACEHOLDER );
	},
	save: () => null,
} );
