/**
 * Field keys and choice option values in the block editor (events-and-forms.md, "Fields"). A key comes
 * from the field's title and an option's value from its label, the same way as `Kalaka\Forms\KeyDerivation`
 * on the server, and the editor sets each one once and never changes it after. A key is unique across the
 * form's event (its participant fields, retired ones included). The server takes any key it receives as
 * chosen on purpose: it derives only a missing or invalid one, and refuses a key repeated in the form.
 *
 * Nothing here imports WordPress: the editor passes in its block-editor store (the three selectors below),
 * so the rules are tested on their own.
 */

import { fieldTypes } from './server';


const KEY_PATTERN = /^[a-z][a-z0-9_]{0,63}$/;
const VALUE_PATTERN = /^[a-z0-9][a-z0-9_-]{0,63}$/;
const MAX_LENGTH = 64;

const ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ' };

/**
 * Whether the block is a field block, with a key of its own: a registered field type's block, extension
 * field types included (`server.js`); a choice field also has `options`.
 */
export const isField = (name) =>
	typeof name === 'string' && Object.hasOwn(fieldTypes(), name);

/** The text of a title or label as authored (rich text: tags and entities), as plain text. */
const plain = (html) =>
	String(html ?? '')
		.replace(/<[^>]*>/g, '')
		.replace(/&(#x[0-9a-f]+|#[0-9]+|[a-z]+);/gi, (entity, name) => {
			if (name[0] === '#') {
				const code = name[1] === 'x' || name[1] === 'X' ? parseInt(name.slice(2), 16) : parseInt(name.slice(1), 10);
				// An out-of-range code point stays as written, as the browser shows it.
				return Number.isInteger(code) && code >= 0 && code <= 0x10ffff ? String.fromCodePoint(code) : entity;
			}
			return ENTITIES[name.toLowerCase()] ?? entity;
		});

/** Letters NFD does not decompose, transliterated as WordPress's `remove_accents()` does. */
const LETTERS = { ß: 'ss', ẞ: 'SS', æ: 'ae', Æ: 'AE', ø: 'o', Ø: 'O', œ: 'oe', Œ: 'OE', đ: 'd', Đ: 'D', ł: 'l', Ł: 'L', þ: 'th', Þ: 'TH', ð: 'd', Ð: 'D', ı: 'i' };

/** The text as `a-z`, `0-9` and single `_` between them: no diacritics, lower case. */
export const slug = (html) =>
	plain(html)
		.replace(/[ßẞæÆøØœŒđĐłŁþÞðÐı]/g, (letter) => LETTERS[letter])
		.normalize('NFD')
		.replace(/[̀-ͯ]/g, '')
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, '_')
		.replace(/^_+|_+$/g, '');

const cut = (text, length) => text.slice(0, length).replace(/_+$/, '');

const unique = (base, taken) => {
	let candidate = cut(base, MAX_LENGTH);
	for (let n = 1; taken.has(candidate); n++) {
		const suffix = '_' + n;
		candidate = cut(base, MAX_LENGTH - suffix.length) + suffix;
	}
	return candidate;
};

/** `kalaka/email-field` → `email`; `field` when nothing usable is left. */
const fallbackKey = (blockName) => {
	const name = String(blockName).slice(String(blockName).lastIndexOf('/') + 1).replace(/-field$/, '');
	return slug(name).replace(/^[0-9_]+/, '') || 'field';
};

/** A key for a field of the block type from its title, unique among the taken keys (a Set). */
export const deriveKey = (title, blockName, taken) =>
	unique(slug(title).replace(/^[0-9_]+/, '') || fallbackKey(blockName), taken);

/** A value for an option from its label, unique among the field's taken values (a Set). */
export const deriveValue = (label, taken) => unique(slug(label) || 'option', taken);

export const isKey = (key) => typeof key === 'string' && KEY_PATTERN.test(key);
export const isValue = (value) => typeof value === 'string' && VALUE_PATTERN.test(value);

/** Whether a title or label has any text yet; until it has, nothing is derived from it while editing. */
const hasText = (html) => plain(html).trim() !== '';

/**
 * The keys of the form's event (its participant fields, retired ones included): a derived key is unique
 * across the event and never takes a key the event ever had (owner ruling 2026-11-11). Only a key the
 * author types or picks may match one. The editor sets them once it has read them (`event-keys.js`):
 * - `none`: the form has no event yet; a key is unique within the form, as the server derives it then;
 * - `loading` or `failed`: no key is derived, since one could join another form's field by accident;
 * - `ready`: a key avoids the event's keys and the form's.
 */
const event = { state: 'none', keys: new Set() };

export const eventKeys = {
	none() {
		event.state = 'none';
		event.keys = new Set();
	},
	loading() {
		event.state = 'loading';
	},
	loaded(keys) {
		event.state = 'ready';
		event.keys = new Set(keys);
	},
	failed() {
		event.state = 'failed';
	},
	state: () => event.state,
};

/**
 * Reads the event's keys through `request` (a function returning a promise of the participant fields) into
 * `eventKeys`, unless `current()` says a later read has begun. Resolves to the fields, or null on a failure.
 */
export const loadEventKeys = (request, current = () => true) => {
	eventKeys.loading();
	return Promise.resolve()
		.then(request)
		.then(
			(fields) => {
				const list = Array.isArray(fields) ? fields : [];
				if (current()) {
					eventKeys.loaded(list.map((field) => field?.key).filter(isKey));
				}
				return list;
			},
			() => {
				if (current()) {
					eventKeys.failed();
				}
				return null;
			}
		);
};

/**
 * After a save, the keys the server set for fields the editor sent without one (the event's keys were not
 * known then): `live` and `saved` are the form's field blocks in document order, `{ clientId, name, key }` and
 * `{ name, key }`. Without them the editor would derive a different key on the next save, and the server
 * would take it as chosen. Only when both lists hold the same blocks in the same order; a Map of clientId →
 * `{ key }` (empty otherwise).
 */
export const savedKeys = (live, saved) => {
	const patches = new Map();
	if (live.length !== saved.length || live.some((block, i) => block.name !== saved[i].name)) {
		return patches;
	}
	live.forEach((block, i) => {
		if (!isKey(block.key) && isKey(saved[i].key)) {
			patches.set(block.clientId, { key: saved[i].key });
		}
	});
	return patches;
};

/** Whether a key may be derived now: the event's keys are known, or the form has no event. */
const canDerive = () => event.state === 'none' || event.state === 'ready';

/** The keys of every field block in the editor except one, and the event's keys. */
const otherKeys = (store, clientId) => {
	const keys = new Set(event.keys);
	for (const id of store.getClientIdsWithDescendants()) {
		if (id !== clientId && isField(store.getBlockName(id))) {
			const key = store.getBlockAttributes(id)?.key;
			if (isKey(key)) {
				keys.add(key);
			}
		}
	}
	return keys;
};

/**
 * The options with a value for each one that has none yet. An option gets its value once it has a label
 * (or, with `fallback`, always: before a save). `fresh` derives all of them again: a copied field.
 * Returns null when nothing changes.
 */
const optionValues = (options, { fresh = false, fallback = false } = {}) => {
	if (!Array.isArray(options)) {
		return null;
	}
	const taken = new Set(fresh ? [] : options.map((option) => option?.value).filter(isValue));
	let changed = false;
	const next = options.map((option) => {
		const label = option?.label ?? '';
		if (!fresh && isValue(option?.value)) {
			return option;
		}
		if (!fresh && !fallback && !hasText(label)) {
			return option;
		}
		const value = deriveValue(label, taken);
		taken.add(value);
		changed = true;
		return { ...option, value };
	});
	return changed ? next : null;
};

/**
 * What a field block is missing: a key once its title has text (or always, with `fallback`), and the
 * values of its options. Returns the attributes to set, or null.
 */
export const missing = (store, clientId, { fallback = false } = {}) => {
	const name = store.getBlockName(clientId);
	const attributes = store.getBlockAttributes(clientId) ?? {};
	const patch = {};
	if (!isKey(attributes.key) && canDerive() && (fallback || hasText(attributes.label))) {
		patch.key = deriveKey(attributes.label, name, otherKeys(store, clientId));
	}
	const options = optionValues(attributes.options, { fallback });
	if (options) {
		patch.options = options;
	}
	return Object.keys(patch).length > 0 ? patch : null;
};

/**
 * A new field's key from the block's title ("back to new" in the participant-field panel); none without the
 * event's keys.
 */
export const freshKey = (store, clientId) =>
	canDerive()
		? deriveKey(store.getBlockAttributes(clientId)?.label, store.getBlockName(clientId), otherKeys(store, clientId))
		: undefined;

/**
 * What a copy gets: a fresh key from its title and fresh values for its options. Also a field that arrives
 * with an event's field's key and does not join it (`participant.js`, `arrival`). Without the event's keys
 * the key is left unset: it is derived once they are known.
 */
export const copied = (store, clientId) => {
	const patch = { key: freshKey(store, clientId) };
	const options = optionValues(store.getBlockAttributes(clientId)?.options, { fresh: true });
	if (options) {
		patch.options = options;
	}
	return patch;
};

/**
 * Keeps keys and option values stable across the editor's field blocks. One keeper serves every field
 * block of the editor, so it must live in the one editor script that registers them all.
 *
 * The editor gives a duplicated or pasted block the same attributes as its original, wherever it lands
 * (also above the original). A block that mounts holding a key that a block mounted before it already
 * holds is the copy: it gets a fresh key from its title, and its options fresh values. Every other block
 * keeps what it has, so a moved or cut-and-pasted field keeps its key. A block that arrives holding a key of
 * one of the event's participant fields that the form did not hold (pasted from another form) is not kept
 * silently either: the editor asks whether it joins that field (`participant-prompts.js`).
 */
export const createKeeper = () => {
	const seen = new Set();

	return {
		/** Whether the block has mounted before (it remounts when it moves into another block). */
		has: (clientId) => seen.has(clientId),
		/** Called when a field block mounts. Returns the attributes to set, or null. */
		mount(store, clientId) {
			const attributes = store.getBlockAttributes(clientId) ?? {};
			const key = attributes.key;
			const copy =
				isKey(key) &&
				store
					.getClientIdsWithDescendants()
					.some(
						(id) =>
							id !== clientId &&
							seen.has(id) &&
							isField(store.getBlockName(id)) &&
							store.getBlockAttributes(id)?.key === key
					);
			seen.add(clientId);
			return copy ? copied(store, clientId) : missing(store, clientId);
		},
	};
};

/**
 * Before a save: every field block without a key or an option without a value gets one, as the server
 * would derive it, in document order. Returns a Map of clientId → attributes to set (empty when none).
 * Without `fallback` (an autosave), a field or an option with no text yet stays without one, so it is
 * derived from its text once that is typed, not frozen to the fallback.
 */
export const beforeSave = (store, { fallback = true } = {}) => {
	const patches = new Map();
	const view = {
		getClientIdsWithDescendants: () => store.getClientIdsWithDescendants(),
		getBlockName: (id) => store.getBlockName(id),
		getBlockAttributes: (id) => ({ ...store.getBlockAttributes(id), ...patches.get(id) }),
	};
	for (const id of store.getClientIdsWithDescendants()) {
		if (isField(store.getBlockName(id))) {
			const patch = missing(view, id, { fallback });
			if (patch) {
				patches.set(id, patch);
			}
		}
	}
	return patches;
};

/** The blocks with the patches of `beforeSave` applied (a new tree; the blocks given are not changed). */
export const withPatches = (blocks, patches) =>
	blocks.map((block) => {
		const patch = patches.get(block.clientId);
		const innerBlocks = withPatches(block.innerBlocks ?? [], patches);
		return patch || innerBlocks.some((inner, i) => inner !== block.innerBlocks[i])
			? { ...block, attributes: { ...block.attributes, ...patch }, innerBlocks }
			: block;
	});

/** A title or label as plain text: no tags or entities (for the editor's own labels). */
export const plainText = (html) => plain(html).trim();
