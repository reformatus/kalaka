/**
 * The ranking field in the editor (`Blocks/ranking-field/edit.js`): it is built from core's field-editor pieces
 * (`window.kalakaFieldEditor`), so the form editor's one keeper of keys gives a copied ranking a fresh key.
 */
import { createKeeper } from '../../../kalaka/src/Forms/Blocks/form/keys';
import Edit from '../../src/Ranking/Blocks/ranking-field/edit';

// The block editor's packages are not installed for Jest: the ranking's editor uses only these of them.
jest.mock('@wordpress/block-editor', () => ({ useBlockProps: (props = {}) => props }), { virtual: true });
jest.mock(
	'@wordpress/components',
	() => ({ PanelBody: 'PanelBody', SelectControl: 'SelectControl', TextControl: 'TextControl' }),
	{ virtual: true }
);
jest.mock('@wordpress/api-fetch', () => () => new Promise(() => {}), { virtual: true });
jest.mock('@wordpress/data', () => ({ useSelect: () => 0 }), { virtual: true });
// The editor is called as a function here, outside React: its own state is its first value.
jest.mock('@wordpress/element', () => ({
	...jest.requireActual('@wordpress/element'),
	useState: (value) => [value, () => {}],
	useEffect: () => {},
}));

const RANKING = 'kalaka/ranking-field';

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

const ranking = (clientId, attributes) => ({ clientId, name: RANKING, attributes });

describe('a copied ranking', () => {
	const fieldTypes = globalThis.kalakaFormEditor.fieldTypes;
	beforeEach(() => {
		// What the server prints with the ranking plugin active (`Forms\Module::editorData()`).
		globalThis.kalakaFormEditor = { ...globalThis.kalakaFormEditor, fieldTypes: { ...fieldTypes, [RANKING]: 'choice' } };
	});
	afterEach(() => {
		globalThis.kalakaFormEditor = { ...globalThis.kalakaFormEditor, fieldTypes };
	});

	test('gets a fresh key from its title; the original keeps its own', () => {
		const store = editor([ranking('a', { key: 'modulok', label: 'Modulok', set: 1 })]);
		const keeper = createKeeper();
		store.update('a', keeper.mount(store, 'a'));

		// The editor gives a duplicate the original's attributes; the copy mounts after the original.
		store.insert(ranking('copy', { ...store.getBlockAttributes('a') }), 1);
		store.update('copy', keeper.mount(store, 'copy'));

		expect(store.getBlockAttributes('a').key).toBe('modulok');
		expect(store.getBlockAttributes('copy')).toEqual({ key: 'modulok_1', label: 'Modulok', set: 1 });
	});
});

describe('the ranking’s editor uses core’s field-editor pieces', () => {
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

	/** The elements of a tree whose type is `type`, depth first. */
	const find = (node, type) => {
		if (node === null || typeof node !== 'object') {
			return [];
		}
		if (Array.isArray(node)) {
			return node.flatMap((child) => find(child, type));
		}
		const own = node.type === type ? [node] : [];
		return [...own, ...find(node.props?.children, type), ...find(node.props?.settings, type)];
	};

	test('the keeper, the canvas marker, the inspector with its panels and the title', () => {
		const attributes = { key: 'modulok', label: 'Modulok', required: true, min: 1, max: 2, set: 1 };
		const tree = Edit({ name: RANKING, attributes, setAttributes: jest.fn(), clientId: 'c1', isSelected: true });

		expect(pieces.useStableKey).toHaveBeenCalledWith('c1', true);
		expect(pieces.useFieldCanvas).toHaveBeenCalledWith(RANKING, 'c1', attributes);
		expect(tree.props.className).toBe('has-kalaka-problem');
		const [inspector] = find(tree, 'FieldInspector');
		expect(inspector.props).toMatchObject({ name: RANKING, clientId: 'c1', attributes, toggles: [pieces.REQUIRED] });
		expect(inspector.props.settings.map((count) => count.props.label)).toEqual([
			'Legkevesebb választás',
			'Legtöbb választás',
		]);
		// The set picker, after the settings.
		expect(inspector.props.children.props).toMatchObject({ value: 1 });
		const [title] = find(tree, 'FieldTitle');
		expect(title.props).toMatchObject({ name: RANKING, required: true, onBlur: commit });
	});
});
