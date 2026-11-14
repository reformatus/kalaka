/**
 * The form editor: the one editor script of every Kaláka block (events-and-forms.md, "Forms
 * implementation"). The field, condition, page and after-submit blocks name this script's handle in their
 * `block.json`, so their editors share one module, and with it one keeper of field keys (`keys.js`).
 *
 * Which blocks an editor offers (fields and conditions only in a form) is decided on the server, not here.
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { __unstableSerializeAndClean, getBlockType, registerBlockType, unregisterBlockType } from '@wordpress/blocks';
import { dispatch, select } from '@wordpress/data';
import domReady from '@wordpress/dom-ready';
import { addFilter } from '@wordpress/hooks';
import { hide } from './hidden';
import { hiddenBlocks } from './server';
import { beforeSave, plainText, withPatches } from './keys';
import { branchLabel, branchSave } from './branch';
import formEdit from './edit';
import nameEdit from '../name-field/edit';
import emailEdit from '../email-field/edit';
import phoneEdit from '../phone-field/edit';
import dateEdit from '../date-field/edit';
import textEdit from '../text-field/edit';
import choiceEdit from '../choice-field/edit';
import consentEdit from '../consent-field/edit';
import ifEdit from '../if/edit';
import elseIfEdit from '../else-if/edit';
import elseEdit from '../else/edit';
import pageEdit from '../form-page/edit';
import afterSubmitEdit from '../after-submit/edit';
import './style.css';
import './editor.css';
import './problems';
import './participant-view';
import './participant-prompts';

/** A field's label in the list view, breadcrumb and toolbar: its title as text, or the block's title. */
const fieldLabel = (attributes) => plainText(attributes.label) || undefined;

/** A condition block's label: its title, and a summary of its condition. */
const conditionLabel = (name) => (attributes) => branchLabel(getBlockType(name)?.title ?? '')(attributes);

/** A page's label: its title, or the block's title. */
const pageLabel = (attributes) => plainText(attributes.label) || undefined;

const field = (edit) => ({ edit, __experimentalLabel: fieldLabel });
const blocks = {
	'kalaka/form': { edit: formEdit },
	'kalaka/name-field': field(nameEdit),
	'kalaka/email-field': field(emailEdit),
	'kalaka/phone-field': field(phoneEdit),
	'kalaka/date-field': field(dateEdit),
	'kalaka/text-field': field(textEdit),
	'kalaka/choice-field': field(choiceEdit),
	'kalaka/consent-field': field(consentEdit),
	'kalaka/if': { edit: ifEdit, save: branchSave, __experimentalLabel: conditionLabel('kalaka/if') },
	'kalaka/else-if': { edit: elseIfEdit, save: branchSave, __experimentalLabel: conditionLabel('kalaka/else-if') },
	'kalaka/else': { edit: elseEdit, save: branchSave },
	'kalaka/form-page': { edit: pageEdit, save: branchSave, __experimentalLabel: pageLabel },
	'kalaka/after-submit': { edit: afterSubmitEdit, save: branchSave },
};

for (const [name, settings] of Object.entries(blocks)) {
	registerBlockType(name, settings);
}

// Before every save: a field the editor has not given a key yet, or an option without a value, gets one
// now, so the server never derives one for a form built here. An autosave sets only those that have text:
// a field still without a title is not frozen to its type's name. The editor has already serialized the
// content when this runs, so the content is serialized again with them.
addFilter('editor.preSavePost', 'kalaka/form/keys', async (edits, options) => {
	const patches = beforeSave(select(blockEditorStore), { fallback: !options?.isAutosave });
	if (patches.size === 0) {
		return edits;
	}
	const { updateBlockAttributes, __unstableMarkNextChangeAsNotPersistent } = dispatch(blockEditorStore);
	for (const [clientId, patch] of patches) {
		__unstableMarkNextChangeAsNotPersistent();
		updateBlockAttributes(clientId, patch);
	}
	const content = __unstableSerializeAndClean(withPatches(select('core/editor').getEditorBlocks(), patches));
	// The editor took its content edit before this filter; set it to what is saved, so the save clears it
	// and leaves the editor clean (the patched blocks are a transient edit).
	dispatch('core/editor').editPost({ content }, { undoIgnore: true });
	return { ...edits, content };
});

// Outside a form's editor: the gated blocks the server named are not offered (`hidden.js`).
domReady(() => hide(hiddenBlocks(), { getBlockType, unregisterBlockType }));
