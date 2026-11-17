/**
 * The ranking field's editor script: registers the block's editor (`edit.js`). Its key is kept by the form
 * editor's one keeper, reached through core's field-editor pieces (`window.kalakaFieldEditor`); `block.json`
 * names the form editor's script (`kalaka-form-editor-script`) first, so they are there before this runs. Its
 * options are its set's.
 */
import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import edit from './edit';
import './style.css';

// The list view and the breadcrumb name the field by its title, as core's field blocks do.
registerBlockType(metadata.name, { edit, __experimentalLabel: window.kalakaFieldEditor.fieldLabel });
