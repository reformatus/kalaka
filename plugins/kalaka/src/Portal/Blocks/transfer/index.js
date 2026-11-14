/**
 * The transfer block in the editor: a placeholder only; the server renders the bank-transfer details of
 * the visitor's active registration while something is due.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { createElement } from '@wordpress/element';
import metadata from './block.json';

const PLACEHOLDER =
	'Átutalási adatok: itt jelennek meg az átutaláshoz szükséges adatok, ha a látogató aktív jelentkezésénél még van fizetendő összeg.';

registerBlockType( metadata.name, {
	edit: function Edit() {
		return createElement( 'p', useBlockProps(), PLACEHOLDER );
	},
	save: () => null,
} );
