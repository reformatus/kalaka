// The participant-field verdicts and questions of the form editor (participant.js), on #169's read shape.
import { copied, eventKeys } from '../../src/Forms/Blocks/form/keys';
import {
	arrival,
	declining,
	differences,
	fieldType,
	joinable,
	problemOf,
	renaming,
	retiredOfType,
	tooLong,
	statusText,
	verdict,
} from '../../src/Forms/Blocks/form/participant';

const T = 'kalaka/text-field';
const P = 'kalaka/phone-field';
const C = 'kalaka/choice-field';
const field = (key, type, title, source, more = {}) => ({
	key,
	type,
	type_label: { [T]: 'Szöveg', [P]: 'Telefonszám' }[type] ?? type,
	title,
	options: [],
	source,
	form_writable: source === 'form' || source === 'staff',
	retired: false,
	forms: [],
	...more,
});
const fields = [
	field('telefon', P, 'Telefonszám', 'form', { forms: [{ form_id: 8, title: 'A' }, { form_id: 41, title: 'B' }] }),
	field('megjegyzes', T, 'Megjegyzés', 'form', { forms: [{ form_id: 41, title: 'B' }] }),
	field('szobaszam', T, 'Szobaszám', 'staff'),
	field('jelentkezes', 'kalaka/date-field', 'Jelentkezés ideje', 'system'),
	field('tamogatas', 'kalaka-aid/award', 'Megítélt támogatás', 'extension'),
	field('kod', T, 'Kód', 'extension', { form_writable: true }),
	field('iskola', T, 'Iskola', 'form', { retired: true }),
];
const of = (key, type = T, formKeys = new Set()) => verdict({ key, type, fields, formId: 41, formKeys });

describe('participant-field verdicts', () => {
	test('the type is the block name (#169)', () => {
		expect(fieldType(P)).toBe(P);
	});
	test('an unknown key is a new field; one only this form holds is its own', () => {
		expect(of('uj').state).toBe('new');
		expect(of('megjegyzes').state).toBe('own');
	});
	test('a key another form or staff writes is a join, naming them', () => {
		expect(of('telefon', P)).toMatchObject({ state: 'join', others: ['A'] });
		expect(of('szobaszam')).toMatchObject({ state: 'join', others: ['munkatársak'] });
		expect(statusText(of('telefon', P))).toBe('Meglévő mezőhöz csatlakozik: „Telefonszám”');
	});
	test('a retired key of the same type rejoins it; of another type it clashes', () => {
		expect(of('iskola').state).toBe('rejoin');
		expect(of('iskola', P).state).toBe('clash');
		expect(problemOf(of('iskola'))).toBeNull();
	});
	test('refused: another type (type_clash), a field no form writes (system_field, extension_field)', () => {
		expect(of('telefon').state).toBe('clash');
		expect(problemOf(of('telefon'))).toBe(
			'Ezt az azonosítót már egy más típusú résztvevői mező használja: „Telefonszám” (Telefonszám).'
		);
		expect(of('jelentkezes', 'kalaka/date-field').state).toBe('readOnly');
		expect(of('tamogatas', 'kalaka-aid/award').state).toBe('readOnly');
		expect(of('kod').state).toBe('own');
		expect(problemOf(of('uj'))).toBeNull();
	});
	test('malformed and repeated keys', () => {
		expect(of('Rossz').state).toBe('invalid');
		expect(of('telefon', P, new Set(['telefon'])).state).toBe('duplicate');
	});
	test('the picker: same type, writable by a form, not the own key; retired ones apart', () => {
		expect(joinable(fields, T, 'megjegyzes').map((f) => f.key)).toEqual(['szobaszam', 'kod']);
		expect(retiredOfType(fields, T).map((f) => f.key)).toEqual(['iskola']);
	});
});

describe('a field block that arrives (pasted or inserted)', () => {
	// The event's keys are module state: reset them even when a test fails.
	afterEach(() => eventKeys.none());

	const arrived = (key, type = T, more = {}) =>
		arrival({ key, type, fields, formKeys: new Set(), savedKeys: new Set(), ...more });

	test('with a field of the same type a form writes: the author is asked', () => {
		expect(arrived('telefon', P)).toEqual({ kind: 'ask', field: fields[0] });
		expect(arrived('szobaszam')).toMatchObject({ kind: 'ask' });
		expect(arrived('iskola')).toMatchObject({ kind: 'ask' });
	});
	test('another type, or a field no form writes: a fresh key, told', () => {
		expect(arrived('telefon')).toEqual({ kind: 'fresh', field: fields[0] });
		expect(arrived('jelentkezes', 'kalaka/date-field')).toMatchObject({ kind: 'fresh' });
		expect(arrived('tamogatas', 'kalaka-aid/award')).toMatchObject({ kind: 'fresh' });
	});
	test('nothing to ask: no field has the key, the form held it when saved, or it is a copy', () => {
		expect(arrived('uj')).toBeNull();
		expect(arrived(undefined)).toBeNull();
		expect(arrived('telefon', P, { savedKeys: new Set(['telefon']) })).toBeNull();
		expect(arrived('telefon', P, { formKeys: new Set(['telefon']) })).toBeNull();
	});
	test('a new field instead gets what a copy gets: a fresh key and fresh option values', () => {
		eventKeys.loaded(fields.map((f) => f.key));
		const blocks = {
			p: { name: C, attributes: { key: 'telefon', label: 'Telefonszám', options: [{ label: 'Igen', value: 'x' }] } },
		};
		const store = {
			getClientIdsWithDescendants: () => Object.keys(blocks),
			getBlockName: (id) => blocks[id].name,
			getBlockAttributes: (id) => blocks[id].attributes,
		};
		expect(copied(store, 'p')).toEqual({ key: 'telefonszam', options: [{ label: 'Igen', value: 'igen' }] });
	});
});

describe('where a block’s texts differ from its field’s (title, option labels)', () => {
	const asks = (key, label, declined = null, type = T) =>
		differences({ label, result: of(key, type), declined }).length > 0;

	test('asked for a form or staff field whose title differs from the label', () => {
		expect(asks('megjegyzes', 'Egyéb megjegyzés')).toBe(true);
		expect(asks('telefon', 'Mobil', null, P)).toBe(true);
		expect(asks('szobaszam', 'Szoba')).toBe(true);
		expect(asks('iskola', 'Iskolád neve')).toBe(true);
	});
	test('not asked: the same text, an empty label, or one the author declined', () => {
		expect(asks('megjegyzes', '<strong>Megjegyzés</strong> ')).toBe(false);
		expect(asks('megjegyzes', '')).toBe(false);
		expect(asks('megjegyzes', 'Egyéb', { title: 'Egyéb' })).toBe(false);
		expect(asks('megjegyzes', 'Egyéb!', { title: 'Egyéb' })).toBe(true);
	});
	test('not asked: a new field, a refused one, or one whose texts are its declarer’s', () => {
		expect(asks('uj', 'Új')).toBe(false);
		expect(asks('telefon', 'Mobil')).toBe(false);
		expect(asks('kod', 'Kód 2')).toBe(false);
	});

	const choice = field('szallas', C, 'Szállás', 'form', {
		options: [
			{ value: 'igen', label: 'Igen', retired: false },
			{ value: 'nem', label: 'Nem', retired: false },
		],
	});
	const found = (label, options, declined = null) =>
		differences({
			label,
			options,
			result: verdict({ key: 'szallas', type: C, fields: [choice], formId: 41, formKeys: new Set() }),
			declined,
		});

	test('option labels are matched by value; a new option is no difference', () => {
		expect(
			found('Szállás', [
				{ value: 'igen', label: 'Igen, kérek' },
				{ value: 'nem', label: 'Nem' },
				{ value: 'talan', label: 'Talán' },
			])
		).toEqual([{ kind: 'option', value: 'igen', from: 'Igen', to: 'Igen, kérek' }]);
	});
	test('title and options in one list, the title first; declined ones are left out until they change', () => {
		const options = [
			{ value: 'igen', label: 'Persze' },
			{ value: 'nem', label: 'Nem kérek' },
		];
		const all = found('Kérsz szállást?', options);
		expect(all.map((item) => item.kind + (item.value ?? ''))).toEqual(['title', 'optionigen', 'optionnem']);
		const declined = declining(null, all.slice(1));
		expect(declined).toEqual({ title: undefined, options: { igen: 'Persze', nem: 'Nem kérek' } });
		expect(found('Kérsz szállást?', options, declined).map((item) => item.kind)).toEqual(['title']);
		expect(found('Szállás', [{ value: 'igen', label: 'Persze!' }], declined)).toHaveLength(1);
	});
	test('the rename carries the chosen differences: { title?, options? }', () => {
		const all = found('Kérsz szállást?', [{ value: 'igen', label: 'Persze' }]);
		expect(renaming(all)).toEqual({ title: 'Kérsz szállást?', options: [{ value: 'igen', label: 'Persze' }] });
		expect(renaming(all.slice(1))).toEqual({ options: [{ value: 'igen', label: 'Persze' }] });
		expect(renaming(all.slice(0, 1))).toEqual({ title: 'Kérsz szállást?' });
	});
	test('texts are sent trimmed; one over 255 characters is not sent', () => {
		expect(renaming([{ kind: 'title', from: 'A', to: '  Új cím ' }])).toEqual({ title: 'Új cím' });
		expect(renaming([])).toEqual({});
		expect(tooLong('é'.repeat(255) + '  ')).toBe(false);
		expect(tooLong('é'.repeat(256))).toBe(true);
	});
});
