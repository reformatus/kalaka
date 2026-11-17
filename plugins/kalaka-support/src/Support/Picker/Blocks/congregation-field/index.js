/**
 * The congregation field's editor script: registers the block's editor (`edit.js`). Its key is kept by the
 * form editor, which handles every field type's block (`kalaka-form-editor-script`).
 */
import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import edit from './edit';

registerBlockType(metadata.name, { edit });
