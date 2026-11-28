/**
 * The sticky start columns: which leading columns stay (the checkbox, Kód, the name) and where each one starts.
 */
import { fitSticky, lefts, stickyCount, STICKY_SHARE } from '../../src/Admin/app/grid/sticky';

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

describe( 'how much of the room the sticky columns may take', () => {
	// The measured widths of the prototype: checkbox 48, Kód 58, name 126, actions 98 at 1440 (1236 px visible); at
	// 375 the checkbox is 56 and the visible width 351.
	test( 'the share is a quarter', () => {
		expect( STICKY_SHARE ).toBe( 0.25 );
	} );

	test( 'wide: the checkbox, Kód and the name fit a quarter of the width, in that order', () => {
		expect( fitSticky( [ 48, 58, 126 ], null, 1236 ) ).toEqual( { count: 3, actions: false } );
	} );

	test( 'narrow: only what fits stays; the checkbox first, then Kód, then the name', () => {
		expect( fitSticky( [ 56, 58, 126 ], 98, 351 ) ).toEqual( { count: 1, actions: false } );
		expect( fitSticky( [ 56, 58, 126 ], 98, 460 ) ).toEqual( { count: 2, actions: false } ); // 115 of 115 fits Kód
		expect( fitSticky( [ 56, 58, 126 ], 98, 200 ) ).toEqual( { count: 0, actions: false } );
	} );

	test( 'the first column that does not fit stops the run: a later, smaller one never sticks alone', () => {
		expect( fitSticky( [ 56, 200, 20 ], null, 800 ) ).toEqual( { count: 1, actions: false } );
	} );

	test( 'the actions column sticks only when every start column does and it fits the same budget', () => {
		expect( fitSticky( [ 48, 58, 126 ], 98, 1236 ) ).toEqual( { count: 3, actions: false } ); // 330 > 309
		expect( fitSticky( [ 48, 58, 126 ], 98, 1400 ) ).toEqual( { count: 3, actions: true } ); // 330 <= 350
		expect( fitSticky( [ 40 ], 40, 351 ) ).toEqual( { count: 1, actions: true } ); // a table with only a checkbox: 80 of 87.75
		expect( fitSticky( [ 56, 58 ], 20, 351 ) ).toEqual( { count: 1, actions: false } ); // Kód did not fit: no actions
	} );

	test( 'a table with no start columns and an actions column: the actions column alone, when it fits', () => {
		expect( fitSticky( [], 60, 351 ) ).toEqual( { count: 0, actions: true } );
		expect( fitSticky( [], 98, 351 ) ).toEqual( { count: 0, actions: false } );
	} );
} );

describe( 'the loose actions column', () => {
	test( 'the stylesheet lets DataViews\' sticky actions cells, their divider and the sum row\'s cell go loose together', () => {
		const css = require( 'fs' ).readFileSync( 'plugins/kalaka/src/Admin/app/grid/grid.css', 'utf8' );
		expect( css ).toMatch( /\[data-kg-actions-loose\] \.dataviews-view-table__actions-column--sticky,\s*#kalaka-grid \.kg-dv\[data-kg-actions-loose\] thead tr\.kg-sumtr td\.kg-sumtd-actions \{ position: static; \}/ );
		expect( css ).toMatch( /\[data-kg-actions-loose\] \.dataviews-view-table__actions-column--stuck::after \{ display: none; \}/ );
	} );
} );
