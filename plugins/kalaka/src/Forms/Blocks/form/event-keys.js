/**
 * The keys of the form's event, for key derivation (`keys.js`): the event's participant fields, retired ones
 * included (`GET kalaka-admin/v1/events/{id}/participant-fields?include_retired=true`), read once when the
 * editor knows the form's event and again after every save that is not an autosave. While they load, or
 * when the read fails, no key is derived; a field without a key says so in the canvas (`KeyNotice`). A field
 * saved without a key gets the one the server derived, from the saved content, once the save succeeds.
 */
import apiFetch from '@wordpress/api-fetch';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { parse } from '@wordpress/blocks';
import { Notice } from '@wordpress/components';
import { createReduxStore, dispatch, register, select, subscribe, useSelect } from '@wordpress/data';
import { createElement as el } from '@wordpress/element';
import { eventKeys, isField, isKey, loadEventKeys, savedKeys } from './keys';

export const STORE = 'kalaka/event-keys';

register(
	createReduxStore(STORE, {
		reducer: (state = { eventId: 0, state: 'none', fields: [] }, action) => {
			switch (action.type) {
				case 'SET':
					return { eventId: action.eventId, state: action.state, fields: action.fields };
				case 'FIELD':
					// One field as the server answered a change of it (a new title), in its place.
					return {
						...state,
						fields: state.fields.map((field) => (field.key === action.field.key ? action.field : field)),
					};
				default:
					return state;
			}
		},
		actions: {
			set: (eventId, state, fields = []) => ({ type: 'SET', eventId, state, fields }),
			setField: (field) => ({ type: 'FIELD', field }),
		},
		selectors: {
			getState: (state) => state.state,
			getEventId: (state) => state.eventId,
			/** The event's participant fields as read (the route's shape); empty until read. */
			getFields: (state) => state.fields,
		},
	})
);

/** Reads the event's keys; a later read for another event wins over an earlier one still under way. */
let reading = 0;
function read(eventId) {
	const ticket = ++reading;
	if (!eventId) {
		eventKeys.none();
		dispatch(STORE).set(0, 'none');
		return;
	}
	dispatch(STORE).set(eventId, 'loading', select(STORE).getEventId() === eventId ? select(STORE).getFields() : []);
	loadEventKeys(
		() => apiFetch({ path: `/kalaka-admin/v1/events/${eventId}/participant-fields?include_retired=true` }),
		() => ticket === reading
	).then((fields) => {
		if (ticket === reading) {
			dispatch(STORE).set(eventId, fields === null ? 'failed' : 'ready', fields ?? []);
		}
	});
}

/** The field blocks of a parsed block list, in document order. */
export const fieldsOf = (blocks) =>
	blocks.flatMap((block) => [...(isField(block.name) ? [block] : []), ...fieldsOf(block.innerBlocks ?? [])]);

/** After a save, the keys the server derived go into the blocks the editor sent without one. */
function takeSavedKeys() {
	const content = select('core/editor').getCurrentPostAttribute('content');
	if (typeof content !== 'string') {
		return;
	}
	const store = select(blockEditorStore);
	const live = store
		.getClientIdsWithDescendants()
		.filter((id) => isField(store.getBlockName(id)))
		.map((id) => ({ clientId: id, name: store.getBlockName(id), key: store.getBlockAttributes(id)?.key }));
	const saved = fieldsOf(parse(content)).map((block) => ({ name: block.name, key: block.attributes?.key }));
	const { updateBlockAttributes, __unstableMarkNextChangeAsNotPersistent } = dispatch(blockEditorStore);
	for (const [clientId, patch] of savedKeys(live, saved)) {
		__unstableMarkNextChangeAsNotPersistent();
		updateBlockAttributes(clientId, patch);
	}
}

// Once the editor knows the form's event, and again when it changes or a save (not an autosave) ends.
let known = null;
let saving = false;
subscribe(() => {
	const editor = select('core/editor');
	if (editor?.getCurrentPostType?.() !== 'kalaka_form') {
		return;
	}
	const eventId = Number(editor.getEditedPostAttribute('meta')?.kalaka_event_id ?? 0) || 0;
	const now = editor.isSavingPost() && !editor.isAutosavingPost();
	const saved = saving && !now;
	// Set before reading: the dispatches in `read` call this listener again.
	saving = now;
	if (saved && editor.didPostSaveRequestSucceed()) {
		takeSavedKeys();
	}
	if (eventId !== known || saved) {
		known = eventId;
		read(eventId);
	}
});

/** In the canvas, as control text: a field the editor could not give a key, because the event's keys failed to load. */
export function KeyNotice({ attributes }) {
	const failed = useSelect((selectStore) => selectStore(STORE).getState() === 'failed', []);
	return failed && !isKey(attributes.key)
		? el(
				Notice,
				{ status: 'warning', isDismissible: false, className: 'kalaka-editor-control', spokenMessage: null },
				'Az esemény résztvevői mezői nem tölthetők be, ezért ennek a mezőnek még nincs azonosítója.'
		  )
		: null;
}
