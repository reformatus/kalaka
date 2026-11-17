// The scale field's editor (scale-field/edit.js): its range settings, and conditions on a scale.
import { rangeChange, pointsOf } from '../../src/Forms/Blocks/scale-field/range';
import { checkCondition, scopeOf } from '../../src/Forms/Blocks/form/branch-condition';

describe('the range settings', () => {
	const range = { min: 1, max: 5 };

	test('a changed bound is kept and the other moves only as far as 2 to 11 points need', () => {
		expect(rangeChange(range, 'max', '7')).toEqual({ min: 1, max: 7 });
		expect(rangeChange(range, 'min', '0')).toEqual({ min: 0, max: 5 });
		expect(rangeChange(range, 'max', '1')).toEqual({ min: 0, max: 1 });
		expect(rangeChange(range, 'max', '20')).toEqual({ min: 10, max: 20 });
		expect(rangeChange(range, 'min', '9')).toEqual({ min: 9, max: 10 });
		expect(rangeChange({ min: 1, max: 11 }, 'min', '-3')).toEqual({ min: -3, max: 7 });
	});

	test('text that is not an integer changes nothing', () => {
		for (const text of ['', '-', '2.5', 'öt']) {
			expect(rangeChange(range, 'min', text)).toBeNull();
		}
	});

	test('the editor keeps the first point within ±100', () => {
		expect(rangeChange(range, 'min', '-500')).toEqual({ min: -100, max: -90 });
		expect(rangeChange(range, 'max', '500')).toEqual({ min: 90, max: 100 });
	});

	test('the points run from the first to the last', () => {
		expect(pointsOf({ min: -1, max: 2 })).toEqual([-1, 0, 1, 2]);
	});
});

describe('a condition on a scale', () => {
	const editor = (blocks) => ({
		getClientIdsWithDescendants: () => blocks.map((b) => b.clientId),
		getBlockName: (id) => blocks.find((b) => b.clientId === id)?.name ?? null,
		getBlockAttributes: (id) => blocks.find((b) => b.clientId === id)?.attributes ?? null,
		getPreviousBlockClientId: (id) => {
			const index = blocks.findIndex((b) => b.clientId === id);
			return index > 0 ? blocks[index - 1].clientId : null;
		},
		getBlockParentsByBlockName: () => [],
	});
	const scope = scopeOf(
		editor([
			{ clientId: 'scale', name: 'kalaka/scale-field', attributes: { key: 'ertekeles' } },
			{ clientId: 'if', name: 'kalaka/if', attributes: {} },
		]),
		'if'
	);
	const when = (operator, value) => ({ match: 'all', items: [{ key: 'ertekeles', operator, value }] });

	test.each(['equals', 'lessThan', 'greaterThan'])('%s a number is fine', (operator) => {
		expect(checkCondition(when(operator, '4'), scope)).toBeNull();
	});

	test('a value that is not a number is a value problem', () => {
		expect(checkCondition(when('equals', 'négy'), scope)).toEqual({ problem: 'value', key: 'ertekeles' });
	});
});
