import { hide } from '../../src/Forms/Blocks/form/hidden';
import { hiddenBlocks } from '../../src/Forms/Blocks/form/server';

describe('hidden blocks outside a form editor', () => {
	const saved = globalThis.kalakaFormEditor;
	afterEach(() => {
		globalThis.kalakaFormEditor = saved;
	});

	test('reads the names the server printed, none when it printed nothing', () => {
		globalThis.kalakaFormEditor = { ...saved };
		delete globalThis.kalakaFormEditor.hidden;
		expect(hiddenBlocks()).toEqual([]);
		globalThis.kalakaFormEditor = { ...saved, hidden: ['kalaka/text-field', 7, 'kalaka/form-page'] };
		expect(hiddenBlocks()).toEqual(['kalaka/text-field', 'kalaka/form-page']);
		globalThis.kalakaFormEditor = { ...saved, hidden: 'kalaka/text-field' };
		expect(hiddenBlocks()).toEqual([]);
	});

	test('unregisters only the registered names', () => {
		const registered = new Set(['kalaka/text-field', 'core/paragraph']);
		const unregisterBlockType = jest.fn((name) => registered.delete(name));
		hide(['kalaka/text-field', 'kalaka/form-page'], {
			getBlockType: (name) => (registered.has(name) ? { name } : undefined),
			unregisterBlockType,
		});
		expect(unregisterBlockType).toHaveBeenCalledTimes(1);
		expect(unregisterBlockType).toHaveBeenCalledWith('kalaka/text-field');
		expect([...registered]).toEqual(['core/paragraph']);
	});
});
