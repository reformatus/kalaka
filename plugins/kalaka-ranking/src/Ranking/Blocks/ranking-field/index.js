/**
 * The ranking field's editor script: registers the block's editor (`edit.js`). Its key and its options'
 * keys are kept by the form editor's one keeper, reached through core's field-editor pieces
 * (`window.kalakaFieldEditor`); `block.json` names the form editor's script (`kalaka-form-editor-script`)
 * first, so they are there before this runs.
 */
import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import edit from './edit';
import './style.css';

registerBlockType(metadata.name, { edit });
