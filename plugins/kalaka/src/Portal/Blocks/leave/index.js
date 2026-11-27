/**
 * The leave block in the editor: its title only (the stand-in label, [UNAPPROVED T1110]); the server renders the button for a visitor who has an
 * active registration or is logged in.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { createElement } from '@wordpress/element';
import metadata from './block.json';

registerBlockType( metadata.name, {
	edit: function Edit() {
		return createElement( 'p', useBlockProps(), metadata.title );
	},
	save: () => null,
} );
