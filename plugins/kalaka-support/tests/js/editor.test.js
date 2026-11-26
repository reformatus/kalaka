/**
 * The congregation field in the editor (`Picker/Blocks/congregation-field/edit.js`): it is built from core's
 * field-editor pieces (`window.kalakaFieldEditor`), so the form editor's one keeper of keys gives a copied
 * field a fresh key.
 */
import { createKeeper } from '../../../kalaka/src/Forms/Blocks/form/keys';
import Edit from '../../src/Support/Picker/Blocks/congregation-field/edit';

// The block editor's packages are not installed for Jest: the field's editor uses only this one of them.
jest.mock('@wordpress/block-editor', () => ({ useBlockProps: (props = {}) => props }), { virtual: true });

const CONGREGATION = 'kalaka/congregation-field';

/** A block-editor store over a list of blocks in document order, as `keys.test.js` in core has it. */
const editor = (blocks) => ({
	getClientIdsWithDescendants: () => blocks.map((block) => block.clientId),
	getBlockName: (id) => blocks.find((block) => block.clientId === id)?.name ?? null,
	getBlockAttributes: (id) => blocks.find((block) => block.clientId === id)?.attributes ?? null,
	insert(block, at) {
		blocks.splice(at, 0, block);
	},
	update(id, patch) {
		const block = blocks.find((b) => b.clientId === id);
		block.attributes = { ...block.attributes, ...patch };
	},
});

const field = (clientId, attributes) => ({ clientId, name: CONGREGATION, attributes });

describe('a copied congregation field', () => {
	const fieldTypes = globalThis.kalakaFormEditor.fieldTypes;
	beforeEach(() => {
		// What the server prints with the support plugin active (`Forms\Module::editorData()`).
		globalThis.kalakaFormEditor = {
			...globalThis.kalakaFormEditor,
			fieldTypes: { ...fieldTypes, [CONGREGATION]: 'congregation' },
		};
	});
	afterEach(() => {
		globalThis.kalakaFormEditor = { ...globalThis.kalakaFormEditor, fieldTypes };
	});

	test('gets a fresh key from its title; the original keeps its own', () => {
		const store = editor([field('a', { key: 'gyulekezet', label: 'Gyülekezet' })]);
		const keeper = createKeeper();
		store.update('a', keeper.mount(store, 'a'));

		// The editor gives a duplicate the original's attributes; the copy mounts after the original.
		store.insert(field('copy', { ...store.getBlockAttributes('a') }), 1);
		store.update('copy', keeper.mount(store, 'copy'));

		expect(store.getBlockAttributes('a').key).toBe('gyulekezet');
		expect(store.getBlockAttributes('copy').key).toBe('gyulekezet_1');
	});
});

describe('the congregation field’s editor uses core’s field-editor pieces', () => {
	const commit = jest.fn();
	const pieces = {
		FieldInspector: 'FieldInspector',
		FieldTitle: 'FieldTitle',
		preview: (tag, props) => ({ type: tag, props: { ...props, disabled: true } }),
		REQUIRED: ['required', 'Kötelező'],
		useFieldCanvas: jest.fn(() => ({ props: { className: 'has-kalaka-problem' }, marker: 'marker' })),
		useStableKey: jest.fn(() => commit),
	};
	beforeEach(() => {
		window.kalakaFieldEditor = pieces;
	});
	afterEach(() => {
		delete window.kalakaFieldEditor;
	});

	test('the keeper, the canvas marker, the inspector with its panels, the title and the search box', () => {
		const attributes = { key: 'gyulekezet', label: 'Gyülekezet', required: true };
		const tree = Edit({ name: CONGREGATION, attributes, setAttributes: jest.fn(), clientId: 'c1', isSelected: false });

		expect(pieces.useStableKey).toHaveBeenCalledWith('c1', false);
		expect(pieces.useFieldCanvas).toHaveBeenCalledWith(CONGREGATION, 'c1', attributes);
		expect(tree.props.className).toBe('has-kalaka-problem');
		const children = tree.props.children;
		const inspector = children.find((child) => child?.type === 'FieldInspector');
		expect(inspector.props).toMatchObject({ name: CONGREGATION, clientId: 'c1', attributes, toggles: [pieces.REQUIRED] });
		const title = children.find((child) => child?.type === 'FieldTitle');
		expect(title.props).toMatchObject({ name: CONGREGATION, required: true, onBlur: commit });
		const search = children.find((child) => child?.type === 'input');
		expect(search.props).toMatchObject({ type: 'search', disabled: true });
	});
});
