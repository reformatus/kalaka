/**
 * The congregation field's editor script: registers the block's editor (`edit.js`). Its key is kept by the
 * form editor's one keeper, reached through core's field-editor pieces (`window.kalakaFieldEditor`);
 * `block.json` names the form editor's script (`kalaka-form-editor-script`) first, so they are there before
 * this runs. It imports the picker's stylesheet, which the build writes to `style-index.css`, the block's `style`.
 */
import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import edit from './edit';
import './style.css';

registerBlockType(metadata.name, { edit });
