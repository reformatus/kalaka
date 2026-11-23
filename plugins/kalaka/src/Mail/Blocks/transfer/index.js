/**
 * The transfer block of a mail in the editor: a placeholder only; the server renders the bank-transfer details
 * when the mail is sent, while something is due.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { createElement } from '@wordpress/element';
import metadata from './block.json';

// [UNAPPROVED T4]
const PLACEHOLDER =
	'Átutalási adatok: a levél elküldésekor itt jelennek meg az átutaláshoz szükséges adatok, ' +
	'ha még van fizetendő összeg.';

registerBlockType( metadata.name, {
	edit: function Edit() {
		return createElement( 'p', useBlockProps(), PLACEHOLDER );
	},
	save: () => null,
} );
