import {
	beforeSave,
	createKeeper,
	deriveKey,
	deriveValue,
	eventKeys,
	isField,
	loadEventKeys,
	missing,
	savedKeys,
	withPatches,
} from '../../src/Forms/Blocks/form/keys';

/**
 * A block-editor store over a list of blocks in document order: what the editor's selectors answer.
 * `insert` and `update` change it as the editor would.
 */
const editor = (blocks = []) => {
	const list = blocks.map((block) => ({ ...block }));
	return {
		getClientIdsWithDescendants: () => list.map((block) => block.clientId),
		getBlockName: (id) => list.find((block) => block.clientId === id)?.name ?? null,
		getBlockAttributes: (id) => list.find((block) => block.clientId === id)?.attributes ?? null,
		insert(block, at = list.length) {
			list.splice(at, 0, { ...block });
		},
		move(id, at) {
			const [block] = list.splice(list.findIndex((b) => b.clientId === id), 1);
			list.splice(at, 0, block);
		},
		update(id, patch) {
			const block = list.find((b) => b.clientId === id);
			if (patch) {
				block.attributes = { ...block.attributes, ...patch };
			}
		},
		attributes(id) {
			return this.getBlockAttributes(id);
		},
	};
};

/** Mounts a block as the editor does: the keeper's answer is set into its attributes. */
const mount = (keeper, store, id) => store.update(id, keeper.mount(store, id));
/** A title or label loses focus, or the block is deselected. */
const commit = (store, id) => store.update(id, missing(store, id));

const field = (clientId, label, more = {}) => ({
	clientId,
	name: 'kalaka/text-field',
	attributes: { label, required: false, ...more },
});

describe('derivation (the seam’s examples, as KeyDerivation.php)', () => {
	test.each([
		['Árvíztűrő tükörfúrógép', 'arvizturo_tukorfurogep'],
		['E-mail-cím', 'e_mail_cim'],
		['2026. évi tábor', 'evi_tabor'],
		['Kicsi (S)', 'kicsi_s'],
		['<strong>Név</strong> &amp; cím', 'nev_cim'],
		// Letters NFD keeps whole, as remove_accents($text, 'en_US') writes them.
		['Straße Ærø Łódź Œuvre', 'strasse_aero_lodz_oeuvre'],
		// An out-of-range numeric entity stays as text instead of throwing.
		['Név &#x110000;', 'nev_x110000'],
	])('key of %s', (title, key) => {
		expect(deriveKey(title, 'kalaka/text-field', new Set())).toBe(key);
	});

	test('a second Név gets nev_1', () => {
		expect(deriveKey('Név', 'kalaka/name-field', new Set(['nev']))).toBe('nev_1');
	});

	test('an option value keeps leading digits', () => {
		expect(deriveValue('1 éjszaka', new Set())).toBe('1_ejszaka');
	});

	test('fallbacks: the block’s short name for a key, option for a value', () => {
		expect(deriveKey('', 'kalaka/email-field', new Set())).toBe('email');
		expect(deriveKey('2026', 'kalaka/email-field', new Set(['email']))).toBe('email_1');
		expect(deriveValue('!!', new Set())).toBe('option');
	});

	test('at most 64 characters, cut so the suffix fits', () => {
		const long = 'a'.repeat(70);
		expect(deriveKey(long, 'kalaka/text-field', new Set())).toBe('a'.repeat(64));
		expect(deriveKey(long, 'kalaka/text-field', new Set(['a'.repeat(64)]))).toBe('a'.repeat(62) + '_1');
		expect(deriveKey('a'.repeat(63) + ' b', 'kalaka/text-field', new Set())).toBe('a'.repeat(63));
	});
});

describe('field keys', () => {
	test('creating a field with a title derives its key', () => {
		const store = editor([field('a', 'Név')]);
		mount(createKeeper(), store, 'a');
		expect(store.attributes('a').key).toBe('nev');
	});

	test('a new empty field gets its key once its title is typed and committed', () => {
		const keeper = createKeeper();
		const store = editor([field('a', '')]);
		mount(keeper, store, 'a');
		expect(store.attributes('a').key).toBeUndefined();
		store.update('a', { label: 'Telefonszám' });
		commit(store, 'a');
		expect(store.attributes('a').key).toBe('telefonszam');
	});

	test('renaming the title afterwards leaves the key unchanged', () => {
		const keeper = createKeeper();
		const store = editor([field('a', 'Név')]);
		mount(keeper, store, 'a');
		store.update('a', { label: 'Teljes név' });
		commit(store, 'a');
		mount(keeper, store, 'a');
		expect(store.attributes('a').key).toBe('nev');
		expect(beforeSave(store).size).toBe(0);
	});

	test('a duplicate gets a new unique key, and the original keeps its own', () => {
		const keeper = createKeeper();
		const store = editor([field('a', 'Név'), field('b', 'Megjegyzés')]);
		mount(keeper, store, 'a');
		mount(keeper, store, 'b');
		store.insert({ ...field('a2', 'Név'), attributes: { ...store.attributes('a') } }, 1);
		mount(keeper, store, 'a2');
		expect(store.attributes('a').key).toBe('nev');
		expect(store.attributes('a2').key).toBe('nev_1');
	});

	test('a duplicate inserted above the original gets the new key, not the original', () => {
		const keeper = createKeeper();
		const store = editor([field('a', 'Név')]);
		mount(keeper, store, 'a');
		store.update('a', { label: 'Teljes név' });
		store.insert({ ...field('copy', ''), attributes: { ...store.attributes('a') } }, 0);
		mount(keeper, store, 'copy');
		expect(store.attributes('a').key).toBe('nev');
		expect(store.attributes('copy').key).toBe('teljes_nev');
		expect(store.getClientIdsWithDescendants()).toEqual(['copy', 'a']);
	});

	test('a field moved or cut and pasted keeps its key', () => {
		const keeper = createKeeper();
		const store = editor([field('a', 'Név')]);
		mount(keeper, store, 'a');
		// Moved: the same block mounts again.
		mount(keeper, store, 'a');
		expect(store.attributes('a').key).toBe('nev');
		// Cut and pasted: the original is gone when the paste mounts.
		const other = editor([{ ...field('p', 'Név'), attributes: { ...store.attributes('a') } }]);
		mount(keeper, other, 'p');
		expect(other.attributes('p').key).toBe('nev');
	});

	test('keys are unique across the form, also inside branches', () => {
		const store = editor([
			field('a', 'Név', { key: 'nev' }),
			{ clientId: 'if', name: 'kalaka/if', attributes: {} },
			field('b', 'Név'),
		]);
		mount(createKeeper(), store, 'b');
		expect(store.attributes('b').key).toBe('nev_1');
	});

	test('before a save every field without a key gets one, an empty title the type’s name', () => {
		const store = editor([
			field('a', 'Név'),
			{ clientId: 'e', name: 'kalaka/email-field', attributes: { label: '' } },
			field('c', 'Név'),
		]);
		const patches = beforeSave(store);
		expect(patches.get('a')).toEqual({ key: 'nev' });
		expect(patches.get('e')).toEqual({ key: 'email' });
		expect(patches.get('c')).toEqual({ key: 'nev_1' });
		const tree = [
			{ clientId: 'a', attributes: { label: 'Név' }, innerBlocks: [] },
			{ clientId: 'x', attributes: {}, innerBlocks: [{ clientId: 'c', attributes: { label: 'Név' }, innerBlocks: [] }] },
		];
		const patched = withPatches(tree, patches);
		expect(patched[1].innerBlocks[0].attributes.key).toBe('nev_1');
		expect(tree[1].innerBlocks[0].attributes.key).toBeUndefined();
	});
});

describe('option values', () => {
	const choice = (clientId, options, more = {}) => ({
		clientId,
		name: 'kalaka/choice-field',
		attributes: { label: 'Szállás', key: 'szallas', options, ...more },
	});

	test('adding an option sets its value from its label once it is typed', () => {
		const keeper = createKeeper();
		const store = editor([choice('c', [])]);
		mount(keeper, store, 'c');
		store.update('c', { options: [{ label: '' }] });
		commit(store, 'c');
		expect(store.attributes('c').options[0].value).toBeUndefined();
		store.update('c', { options: [{ label: '1 éjszaka' }] });
		commit(store, 'c');
		expect(store.attributes('c').options).toEqual([{ label: '1 éjszaka', value: '1_ejszaka' }]);
	});

	test('renaming an option’s label leaves its value unchanged', () => {
		const store = editor([choice('c', [{ label: 'Igen', value: 'igen' }])]);
		mount(createKeeper(), store, 'c');
		store.update('c', { options: [{ label: 'Igen, kérek', value: 'igen' }] });
		commit(store, 'c');
		expect(store.attributes('c').options[0].value).toBe('igen');
	});

	test('a second option with the same label gets a new value, also when moved above the first', () => {
		const store = editor([choice('c', [{ label: 'Igen', value: 'igen' }])]);
		mount(createKeeper(), store, 'c');
		store.update('c', { options: [...store.attributes('c').options, { label: 'Igen' }] });
		commit(store, 'c');
		expect(store.attributes('c').options.map((o) => o.value)).toEqual(['igen', 'igen_1']);
		store.update('c', { options: [...store.attributes('c').options].reverse() });
		commit(store, 'c');
		expect(store.attributes('c').options).toEqual([
			{ label: 'Igen', value: 'igen_1' },
			{ label: 'Igen', value: 'igen' },
		]);
	});

	test('a duplicated choice field gets fresh values from its current labels', () => {
		const keeper = createKeeper();
		const store = editor([choice('c', [{ label: 'Igen, kérek', value: 'igen' }, { label: 'Nem', value: 'nem' }])]);
		mount(keeper, store, 'c');
		store.insert({ ...choice('d', []), attributes: { ...store.attributes('c') } }, 0);
		mount(keeper, store, 'd');
		expect(store.attributes('c').options.map((o) => o.value)).toEqual(['igen', 'nem']);
		expect(store.attributes('d').key).toBe('szallas_1');
		expect(store.attributes('d').options.map((o) => o.value)).toEqual(['igen_kerek', 'nem']);
	});

	test('before a save an option without a label gets the fallback value', () => {
		const store = editor([choice('c', [{ label: 'Igen', value: 'option' }, { label: '' }])]);
		expect(beforeSave(store).get('c')).toEqual({
			options: [
				{ label: 'Igen', value: 'option' },
				{ label: '', value: 'option_1' },
			],
		});
	});
});

/**
 * The owner's key-stability cases, as the editor goes through them: a published form is opened (a new
 * keeper mounts every block), edited and saved. The same cases through the server's save path are in
 * tests/integration/Forms/FieldKeyStabilityTest.php.
 */
describe('key stability (owner’s cases)', () => {
	/** Saves as the editor does: `beforeSave`'s patches go into the blocks. */
	const save = (store) => {
		const patches = beforeSave(store);
		for (const [id, patch] of patches) {
			store.update(id, patch);
		}
		return patches;
	};
	/** Opens the saved form again: a new editor session, every block mounts in document order. */
	const open = (store) => {
		const keeper = createKeeper();
		for (const id of store.getClientIdsWithDescendants()) {
			mount(keeper, store, id);
		}
		return keeper;
	};

	test('(a) publish, rename a field’s title, save again: the key stays', () => {
		const store = editor([field('n', 'Név', { key: 'nev' }), field('m', 'Megjegyzés')]);
		const keeper = createKeeper();
		mount(keeper, store, 'n');
		mount(keeper, store, 'm');
		commit(store, 'm');
		save(store);
		expect(store.attributes('m').key).toBe('megjegyzes');

		open(store);
		store.update('m', { label: 'Egyéb megjegyzés' });
		commit(store, 'm');
		expect(save(store).size).toBe(0);
		expect(store.attributes('m').key).toBe('megjegyzes');
		expect(store.attributes('m').label).toBe('Egyéb megjegyzés');
	});

	test('(b) duplicate a field, move the copy above the original, save: the original keeps its key, the copy gets a new one', () => {
		const store = editor([field('n', 'Név', { key: 'nev' }), field('m', 'Megjegyzés', { key: 'megjegyzes' })]);
		const keeper = open(store);

		// Duplicate: the copy holds the original's attributes and lands right below it.
		store.insert({ ...field('copy', ''), attributes: { ...store.attributes('m') } }, 2);
		mount(keeper, store, 'copy');
		// Move the copy above the original: the same block, which may mount again; it keeps what it has.
		store.move('copy', 1);
		mount(keeper, store, 'copy');
		expect(store.getClientIdsWithDescendants()).toEqual(['n', 'copy', 'm']);

		expect(save(store).size).toBe(0);
		expect(store.attributes('m').key).toBe('megjegyzes');
		expect(store.attributes('copy').key).toBe('megjegyzes_1');

		// Opened again, with the copy now before the original in document order: nothing changes.
		open(store);
		expect(save(store).size).toBe(0);
		expect(store.attributes('copy').key).toBe('megjegyzes_1');
		expect(store.attributes('m').key).toBe('megjegyzes');
	});
});

describe('keys across the event (participant fields)', () => {
	afterEach(() => eventKeys.none());

	const read = (fields) => loadEventKeys(() => Promise.resolve(fields));

	test('a derived key avoids the keys other forms of the event hold', async () => {
		await read([
			{ key: 'telefonszam', retired: false },
			{ key: 'nev', retired: false },
		]);
		const store = editor([field('a', 'Telefonszám')]);
		mount(createKeeper(), store, 'a');
		expect(store.attributes('a').key).toBe('telefonszam_1');
	});

	test('a derived key never takes a retired key', async () => {
		await read([{ key: 'megjegyzes', retired: true }]);
		const store = editor([field('a', 'Megjegyzés')]);
		mount(createKeeper(), store, 'a');
		expect(store.attributes('a').key).toBe('megjegyzes_1');
	});

	test('it counts the keys already in this form too', async () => {
		await read([{ key: 'nev_1', retired: false }]);
		const store = editor([field('a', 'Név', { key: 'nev' }), field('b', 'Név')]);
		const keeper = createKeeper();
		mount(keeper, store, 'a');
		mount(keeper, store, 'b');
		expect(store.attributes('b').key).toBe('nev_2');
	});

	test('when the event’s keys fail to load, no key is derived, not even before a save', async () => {
		expect(await loadEventKeys(() => Promise.reject(new Error('offline')))).toBeNull();
		expect(eventKeys.state()).toBe('failed');
		const store = editor([field('a', 'Megjegyzés')]);
		mount(createKeeper(), store, 'a');
		expect(store.attributes('a').key).toBeUndefined();
		expect(beforeSave(store).size).toBe(0);
	});

	test('while they load, no key is derived; once loaded, the next commit derives it', async () => {
		let resolve;
		const loading = loadEventKeys(() => new Promise((r) => (resolve = r)));
		const store = editor([field('a', 'Megjegyzés')]);
		mount(createKeeper(), store, 'a');
		expect(store.attributes('a').key).toBeUndefined();
		await null; // the request starts on the next tick
		resolve([{ key: 'megjegyzes', retired: false }]);
		await loading;
		commit(store, 'a');
		expect(store.attributes('a').key).toBe('megjegyzes_1');
	});

	test('a copy mounted without the event’s keys loses the copied key until they load', async () => {
		await loadEventKeys(() => Promise.reject(new Error('offline')));
		const store = editor([field('a', 'Név', { key: 'nev' }), field('b', 'Név', { key: 'nev' })]);
		const keeper = createKeeper();
		mount(keeper, store, 'a');
		mount(keeper, store, 'b');
		expect(store.attributes('a').key).toBe('nev');
		expect(store.attributes('b').key).toBeUndefined();
	});

	test('a later read wins over an earlier one still under way', async () => {
		let first;
		const earlier = loadEventKeys(() => new Promise((r) => (first = r)), () => false);
		await read([{ key: 'b', retired: false }]);
		first([{ key: 'a', retired: false }]);
		await earlier;
		const store = editor([field('x', 'B')]);
		mount(createKeeper(), store, 'x');
		expect(store.attributes('x').key).toBe('b_1');
	});
});

describe('keys the server derived', () => {
	const T = 'kalaka/text-field';
	test('a field saved without a key takes the key the server stored, so the next save keeps it', () => {
		const live = [
			{ clientId: 'a', name: T, key: 'nev' },
			{ clientId: 'b', name: T, key: undefined },
		];
		const saved = [
			{ name: T, key: 'nev' },
			{ name: T, key: 'telefonszam' },
		];
		expect([...savedKeys(live, saved)]).toEqual([['b', { key: 'telefonszam' }]]);
	});
	test('nothing when the blocks changed meanwhile', () => {
		expect(savedKeys([{ clientId: 'b', name: T }], [{ name: 'kalaka/date-field', key: 'x' }]).size).toBe(0);
		expect(savedKeys([{ clientId: 'b', name: T }], []).size).toBe(0);
	});
});

describe('field blocks, as the server names them (server.js)', () => {
	test('the core field blocks are fields; other blocks are not', () => {
		expect(isField('kalaka/text-field')).toBe(true);
		expect(isField('kalaka/choice-field')).toBe(true);
		expect(isField('kalaka/if')).toBe(false);
		expect(isField('core/paragraph')).toBe(false);
		expect(isField(null)).toBe(false);
	});

	test('an extension field type the server names is a field, with a key derived like any other', () => {
		const before = globalThis.kalakaFormEditor;
		globalThis.kalakaFormEditor = { ...before, fieldTypes: { ...before.fieldTypes, 'acme/rating-field': 'number' } };
		try {
			expect(isField('acme/rating-field')).toBe(true);
			const keeper = createKeeper();
			const store = editor([{ clientId: 'r', name: 'acme/rating-field', attributes: { label: 'Értékelés' } }]);
			mount(keeper, store, 'r');
			commit(store, 'r');
			expect(store.attributes('r').key).toBe('ertekeles');
		} finally {
			globalThis.kalakaFormEditor = before;
		}
	});
});
