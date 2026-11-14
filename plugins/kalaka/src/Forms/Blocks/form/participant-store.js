/**
 * The form's event's participant fields for the participant-field UI, from the editor's own read
 * (`event-keys.js`: retired ones included, read again after every save, each field naming the forms that
 * hold it), and the hooks the field blocks use.
 */
import apiFetch from '@wordpress/api-fetch';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { getBlockType } from '@wordpress/blocks';
import { dispatch, useSelect } from '@wordpress/data';
import { STORE as EVENT_KEYS } from './event-keys';
import { isField, isKey, plainText } from './keys';
import { fieldType, problemOf, verdict } from './participant';

/** The form's event (its `kalaka_event_id` meta) and ID; event 0 when the form has none. */
export const formContext = (selectStore) => {
	const editor = selectStore('core/editor');
	return {
		inForm: editor.getCurrentPostType() === 'kalaka_form',
		eventId: Number(editor.getEditedPostAttribute('meta')?.kalaka_event_id ?? 0) || 0,
		formId: editor.getCurrentPostId(),
	};
};

/**
 * The event's participant fields, or null until they are read. While a re-read of the same event runs (after a
 * save), the fields read before stay, so an open question is not lost.
 */
export const eventFields = (selectStore) => {
	const { inForm, eventId } = formContext(selectStore);
	if (!inForm || !eventId) {
		return null;
	}
	const keys = selectStore(EVENT_KEYS);
	if (keys.getEventId() !== eventId) {
		return null;
	}
	const state = keys.getState();
	return state === 'ready' || (state === 'loading' && keys.getFields().length > 0) ? keys.getFields() : null;
};

/** The keys of the form's field blocks other than one, as a Set. */
export const formKeysOf = (store, clientId) => {
	const keys = new Set();
	for (const id of store.getClientIdsWithDescendants()) {
		const key = store.getBlockAttributes(id)?.key;
		if (id !== clientId && isField(store.getBlockName(id)) && isKey(key)) {
			keys.add(key);
		}
	}
	return keys;
};

/** The verdict for a field block's key (or `key`, one being typed) among the event's fields. */
export const verdictOf = (selectStore, clientId, key) => {
	const store = selectStore(blockEditorStore);
	return verdict({
		key: key === undefined ? store.getBlockAttributes(clientId)?.key : key,
		type: fieldType(store.getBlockName(clientId)),
		fields: eventFields(selectStore),
		formId: formContext(selectStore).formId,
		formKeys: formKeysOf(store, clientId),
	});
};

/** A field block's name as the editor's own labels give it: its title as text, else its block type's title. */
export const blockLabel = (store, clientId) =>
	plainText(store.getBlockAttributes(clientId)?.label) || getBlockType(store.getBlockName(clientId))?.title || '';

/**
 * Everything a field block's participant-field UI needs: the event (0 without one), its fields (null until
 * read) and the verdict for a key (the block's own, or one being typed).
 */
export function useParticipant(name, clientId) {
	const { eventId, formId, fields, others } = useSelect(
		(selectStore) => ({
			...formContext(selectStore),
			fields: eventFields(selectStore),
			// A string, so the selector's result stays equal while nothing changes.
			others: [...formKeysOf(selectStore(blockEditorStore), clientId)].sort().join(' '),
		}),
		[clientId]
	);
	const formKeys = new Set(others === '' ? [] : others.split(' '));
	const type = fieldType(name);
	return {
		eventId,
		fields,
		type,
		formKeys,
		verdictOf: (key) => verdict({ key, type, fields, formId, formKeys }),
	};
}

/** The participant-field problems of the form's field blocks: `[{ clientId, label, text }]`. */
export const fieldProblems = (selectStore) => {
	if (!eventFields(selectStore)) {
		return [];
	}
	const store = selectStore(blockEditorStore);
	return store
		.getClientIdsWithDescendants()
		.filter((id) => isField(store.getBlockName(id)))
		.flatMap((clientId) => {
			const text = problemOf(verdictOf(selectStore, clientId));
			return text ? [{ clientId, label: blockLabel(store, clientId), text }] : [];
		});
};

/**
 * Changes a participant field's title and option labels (`change`: `{ title?, options?: [{ value, label }] }`)
 * through `PATCH kalaka-admin/v1/events/{id}/participant-fields/{key}`, and puts the field as the server answers
 * into the editor's read. The one place the rename route is called (#169 N10, N11): texts trimmed, not blank,
 * at most 255 characters; options matched by value, only the listed ones change; never an empty change.
 */
export const renameParticipantField = (eventId, key, change) =>
	apiFetch({ path: `/kalaka-admin/v1/events/${eventId}/participant-fields/${key}`, method: 'PATCH', data: change }).then(
		(field) => {
			dispatch(EVENT_KEYS).setField(field);
			return field;
		}
	);
