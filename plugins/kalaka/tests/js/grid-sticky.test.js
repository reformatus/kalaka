/**
 * The sticky start columns: which leading columns stay (the checkbox, Kód, the name) and where each one starts.
 */
import { lefts, stickyCount } from '../../src/Admin/app/grid/sticky';

const columns = [
	{ column: 'code', type: 'code' },
	{ column: 'field:nev', type: 'text', name: true },
	{ column: 'status', type: 'status' },
	{ column: 'field:email', type: 'text' },
];

describe( 'which start columns stay', () => {
	test( 'the checkbox, Kód and the name, in the table\'s order', () => {
		expect( stickyCount( columns, [ 'code', 'field:nev', 'status', 'field:email' ], true ) ).toBe( 3 );
	} );

	test( 'a table with no selection column has no checkbox to keep', () => {
		expect( stickyCount( columns, [ 'code', 'field:nev', 'status' ], false ) ).toBe( 2 );
	} );

	test( 'only the leading identifying columns count: one that is not first or not Kód or the name stops the run', () => {
		expect( stickyCount( columns, [ 'status', 'code', 'field:nev' ], true ) ).toBe( 1 );
		expect( stickyCount( columns, [ 'code', 'status', 'field:nev' ], true ) ).toBe( 2 );
		expect( stickyCount( columns, [ 'field:email' ], false ) ).toBe( 0 );
	} );

	test( 'a text column is not the name unless the column says so', () => {
		expect( stickyCount( columns, [ 'field:email', 'code' ], false ) ).toBe( 0 );
	} );
} );

describe( 'where each one starts', () => {
	test( 'the left edge is the sum of the widths before it', () => {
		expect( lefts( [ 48, 58, 126 ] ) ).toEqual( [ 0, 48, 106 ] );
		expect( lefts( [ 56 ] ) ).toEqual( [ 0 ] );
		expect( lefts( [] ) ).toEqual( [] );
	} );
} );
