import { cellText, sumParts } from '../../src/Admin/app/grid/format';
import { filterSpec } from '../../src/Admin/app/grid/narrow';

// The column as the server describes it ([UNAPPROVED N669], title [UNAPPROVED T852]): a consent-like yes/no
// column that is always answered, so it carries no `empty` flag.
const column = {
	column: 'awaits_answer',
	label: 'Válaszra vár',
	type: 'consent',
	sortable: true,
	numeric: false,
	options: { 1: 'Igen', 0: 'Nem' },
};

describe( 'the „Válaszra vár” column', () => {
	it( 'filters Igen and Nem with is / isNot and has no „Nincs válasz”', () => {
		expect( filterSpec( column ) ).toEqual( {
			type: 'boolean',
			elements: [
				{ value: true, label: 'Igen' },
				{ value: false, label: 'Nem' },
			],
			filterBy: { operators: [ 'is', 'isNot' ] },
		} );
	} );

	it( 'reads Igen and Nem in a cell and in the copy', () => {
		expect( cellText( column, { values: { awaits_answer: '1' } } ) ).toBe( 'Igen' );
		expect( cellText( column, { values: { awaits_answer: '0' } } ) ).toBe( 'Nem' );
	} );

	it( 'counts Igen in the sum row, as a consent column is counted', () => {
		const sums = { awaits_answer: { kind: 'count', member: 'Igen', n: 3 } };
		expect( sumParts( column, sums ) ).toEqual( { shown: '3', full: 'Igen: 3' } );
	} );
} );
