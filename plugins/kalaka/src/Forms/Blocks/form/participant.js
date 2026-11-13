/**
 * What a field block's key means among its form's event's participant fields (owner rulings 2026-11-11 and
 * 2026-11-13): a new participant field, one only this form asks, or a deliberate join of a field of the same
 * type; or a key the server refuses (another type, a field no form may write). The server stays the judge (its
 * save check); this only shows the author beforehand. Also the two questions the editor asks about a
 * participant field: whether a field block that arrives (pasted or inserted) with an existing field's key joins
 * it, and whether a changed label becomes the field's title too. No WordPress imports, so it is tested alone.
 *
 * `fields` is the event's participant-field read (`GET kalaka-admin/v1/events/{id}/participant-fields`), each
 * `{key, type, type_label, title, options: [{value, label, retired}], source, form_writable, retired,
 * forms: [{form_id, title}]}`.
 */
import { isKey, plainText } from './keys';

/** A field block's participant-field type: its block name (`ParticipantField::$type`). */
export const fieldType = (blockName) => String(blockName ?? '');

/** Each source's name, as the editor shows it. */
export const SOURCES = { form: 'Űrlap', staff: 'Munkatársak', extension: 'Bővítmény', system: 'Rendszer' };

/** The sources whose field's title may be changed (`RenameParticipantField`); the others are their declarer's. */
export const RENAMABLE = ['form', 'staff'];

/**
 * The verdict for `key` on a block of `type` in form `formId`; `formKeys` are the keys of the form's other
 * field blocks. `{ state, field?, others? }`, state one of:
 * - `invalid`, `duplicate`: the editor never sets such a key;
 * - `new`: no participant field has the key; saving creates one;
 * - `own`: a participant field only this form asks;
 * - `join`: shared with other forms or staff (`others`: their names);
 * - `rejoin`: a retired field of the same type, which comes back into use;
 * - `clash` (`type_clash`), `readOnly` (`system_field`, `extension_field`): the server refuses the save.
 */
export function verdict({ key, type, fields, formId, formKeys }) {
	if (!isKey(key)) {
		return { state: 'invalid' };
	}
	if (formKeys?.has(key)) {
		return { state: 'duplicate' };
	}
	const field = (fields ?? []).find((candidate) => candidate.key === key);
	if (!field) {
		return { state: 'new' };
	}
	if (!field.form_writable) {
		return { state: 'readOnly', field };
	}
	if (field.type !== type) {
		return { state: 'clash', field };
	}
	if (field.retired) {
		return { state: 'rejoin', field };
	}
	const others = (field.forms ?? []).filter((form) => form.form_id !== formId).map((form) => form.title);
	if (field.source === 'staff') {
		others.push('munkatársak');
	}
	return others.length > 0 ? { state: 'join', field, others } : { state: 'own', field };
}

/** The participant fields a block of `type` may join: same type, writable by a form, not `except`; retired ones apart. */
const ofType = (fields, type, except, retired) =>
	(fields ?? []).filter(
		(field) => field.type === type && field.form_writable && field.retired === retired && field.key !== except
	);

export const joinable = (fields, type, except) => ofType(fields, type, except, false);

/** The same-type retired fields: the picker lists them last, marked; joining one brings it back. */
export const retiredOfType = (fields, type, except) => ofType(fields, type, except, true);

const quoted = (text) => `„${text}”`;

/** The status line of a verdict. */
export const statusText = ({ state, field }) =>
	({
		new: 'Új résztvevői mező',
		own: 'Csak ez az űrlap tölti ki',
		join: field ? `Meglévő mezőhöz csatlakozik: ${quoted(plainText(field.title))}` : '',
		rejoin: field ? `Visszavont mezőhöz csatlakozik: ${quoted(plainText(field.title))} (újra használatba kerül)` : '',
	})[state] ?? '';

/** Who else writes a joined field. */
export const othersText = (others) => `Más is kitölti: ${others.join(', ')}`;

/** The problem a verdict is, as a notice text, or null: the states the server refuses. */
export const problemOf = ({ state, field }) => {
	switch (state) {
		case 'clash':
			return `Ezt az azonosítót már egy más típusú résztvevői mező használja: ${quoted(plainText(field.title))} (${field.type_label || field.type}).`;
		case 'readOnly':
			return `Ezt a résztvevői mezőt nem űrlap tölti ki, ezért űrlap nem csatlakozhat hozzá: ${quoted(plainText(field.title))}.`;
		default:
			return null;
	}
};

/** The help under the key being typed: what saving it would do. */
export const draftText = (result) => {
	switch (result.state) {
		case 'invalid':
			return 'Ékezet nélküli kisbetűvel kell kezdődnie, és csak ékezet nélküli kisbetűt, számjegyet és aláhúzásjelet (_) tartalmazhat.';
		case 'duplicate':
			return 'Ez az azonosító már szerepel ezen az űrlapon.';
		case 'new':
			return 'Ezzel az azonosítóval új résztvevői mező jön létre.';
		case 'own':
		case 'join':
		case 'rejoin':
			return statusText(result);
		default:
			return problemOf(result);
	}
};

/**
 * What a field block that has just arrived in the form (pasted or inserted, never one the form already held)
 * asks, with its `key` and `type` (owner ruling 2026-11-13: never drop a key silently, never join implicitly):
 * - null: nothing; the key is no event's field's, or the form held it when it was last saved (a cut and paste,
 *   an undo), or another block of the form holds it (`formKeys`: a copy, which the keeper gives a fresh key);
 * - `{ kind: 'ask', field }`: a field of the same type a form may write: the author chooses to join it or to
 *   make a new field (a fresh key, as a copy gets);
 * - `{ kind: 'fresh', field }`: joining is impossible (another type, or a field no form writes): a fresh key,
 *   and the author is told.
 * `savedKeys` are the field keys of the form as last saved.
 */
export function arrival({ key, type, fields, formKeys, savedKeys }) {
	if (!isKey(key) || formKeys?.has(key) || savedKeys?.has(key)) {
		return null;
	}
	const field = (fields ?? []).find((candidate) => candidate.key === key);
	if (!field) {
		return null;
	}
	return field.form_writable && field.type === type ? { kind: 'ask', field } : { kind: 'fresh', field };
}

/**
 * Where a field block's text differs from its participant field's (owner rulings 2026-11-13): the field's title
 * and its option labels are the field's own, and a form's label or option label never changes them by itself;
 * the editor asks whether they change too. Only for a block whose key is an existing field's (`result`, its
 * verdict: own, join or rejoin) whose texts may change. Options are matched by value; an option the field does
 * not have yet is no difference. `declined` holds what the author said no to for this block, `{ title, options:
 * { value: text } }` (plain texts), which is not offered again until the text changes. Returns
 * `[{ kind: 'title', from, to } | { kind: 'option', value, from, to }]`, `from` the field's text and `to` the
 * block's (as authored), the title first.
 */
export function differences({ label, options, result, declined }) {
	const field = result?.field;
	if (!['own', 'join', 'rejoin'].includes(result?.state) || !RENAMABLE.includes(field.source)) {
		return [];
	}
	const differs = (text, from, refused) => {
		const plain = plainText(text);
		return plain !== '' && plain !== plainText(from) && plain !== refused;
	};
	const found = [];
	if (differs(label, field.title, declined?.title)) {
		found.push({ kind: 'title', from: field.title, to: label });
	}
	for (const option of Array.isArray(options) ? options : []) {
		const own = (field.options ?? []).find((candidate) => candidate.value === option?.value);
		if (own && differs(option.label, own.label, declined?.options?.[option.value])) {
			found.push({ kind: 'option', value: option.value, from: own.label, to: option.label });
		}
	}
	return found;
}

/** What the author declines with a list of differences, merged into what they declined before. */
export const declining = (declined, found) => {
	const title = found.find((item) => item.kind === 'title');
	return {
		title: title ? plainText(title.to) : declined?.title,
		options: {
			...(declined?.options ?? {}),
			...Object.fromEntries(found.filter((item) => item.kind === 'option').map((item) => [item.value, plainText(item.to)])),
		},
	};
};

/** The longest title or option label the rename takes, in characters (`RenameParticipantField`). */
export const MAX_TEXT = 255;

/** Whether a text is longer than the rename takes, once trimmed. */
export const tooLong = (text) => [...String(text ?? '').trim()].length > MAX_TEXT;

/**
 * The rename that carries the chosen differences over: `{ title?, options? }` (`renameParticipantField`), each
 * text trimmed; only the chosen options. Empty (`{}`) when nothing is chosen: such a rename is never sent.
 */
export const renaming = (chosen) => {
	const change = {};
	const title = chosen.find((item) => item.kind === 'title');
	if (title) {
		change.title = title.to.trim();
	}
	const options = chosen.filter((item) => item.kind === 'option').map(({ value, to }) => ({ value, label: to.trim() }));
	if (options.length > 0) {
		change.options = options;
	}
	return change;
};
