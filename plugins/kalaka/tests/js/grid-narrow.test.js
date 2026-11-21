import {
	CONDITION,
	conditionField,
	lockedFilters,
	filteredIds,
	filterSpec,
	isNarrowed,
	decimal,
	narrowOf,
	NO_ANSWER,
} from '../../src/Admin/app/grid/narrow';

const columns = [
	{ column: 'serial', label: 'Szám', type: 'serial', numeric: true },
	{ column: 'code', label: 'Kód', type: 'code' },
	{ column: 'status', label: 'Állapot', type: 'status', members: [ { value: 'registered', label: 'Rögzítve' } ] },
	{ column: 'field:lunch', label: 'Ebéd', type: 'choice', empty: true, members: [ { value: 'veg', label: 'Vegetáriánus' } ] },
	{ column: 'field:photo', label: 'Fénykép', type: 'consent', empty: true, options: { 1: 'Igen', 0: 'Nem' } },
	{ column: 'field:nights', label: 'Éjszakák', type: 'number', numeric: true },
	{ column: 'field:born', label: 'Születési idő', type: 'date' },
	{ column: 'decision:confirmation', label: 'Visszaigazolás', type: 'decision', empty: true, states: { confirmed: 'Visszaigazolva' } },
	{ column: 'decision:late_fee', label: 'Késedelmi díj', type: 'decision', states: {} },
	{ column: 'field:note', label: 'Megjegyzés', type: 'text' },
];
const by = ( id ) => columns.find( ( c ) => c.column === id );

describe( 'the DataViews filter of each column type', () => {
	it( 'gives a list its members and any / none', () => {
		expect( filterSpec( by( 'status' ) ) ).toEqual( {
			type: 'text',
			elements: [ { value: 'registered', label: 'Rögzítve' } ],
			filterBy: { operators: [ 'isAny', 'isNone' ] },
		} );
		expect( filterSpec( by( 'field:lunch' ) ).filterBy.operators ).toEqual( [ 'isAny', 'isNone' ] );
		// A decision whose states are known is a list of them; one without is text.
		expect( filterSpec( by( 'decision:confirmation' ) ).elements ).toEqual( [
			{ value: 'confirmed', label: 'Visszaigazolva' },
			{ value: NO_ANSWER, label: 'Nincs válasz' },
		] );
		expect( filterSpec( by( 'decision:late_fee' ) ).filterBy.operators ).toEqual( [ 'contains' ] );
	} );

	it( 'keeps yes, no and no answer apart: three elements of the yes/no’s own filter, is / isNot', () => {
		const spec = filterSpec( by( 'field:photo' ) );
		expect( spec.elements ).toEqual( [
			{ value: true, label: 'Igen' },
			{ value: false, label: 'Nem' },
			{ value: NO_ANSWER, label: 'Nincs válasz' },
		] );
		expect( spec.filterBy.operators ).toEqual( [ 'is', 'isNot' ] );
		// A yes/no that is required in every form has no „Nincs válasz”.
		expect( filterSpec( { ...by( 'field:photo' ), empty: undefined } ).elements ).toHaveLength( 2 );
	} );

	it( 'gives a display-only time column no filter and no sort, and any other unknown type text', () => {
		expect( filterSpec( { column: 'registered_at', label: 'Rögzítve', type: 'time', sortable: true } ) ).toEqual( {
			type: 'text',
			filterBy: false,
			enableSorting: false,
		} );
		expect( filterSpec( { column: 'x', label: 'X', type: 'other' } ).filterBy.operators ).toEqual( [ 'contains' ] );
	} );

	it( 'gives every number column, Szám included, =, <, > and from–to, in decimal comma form', () => {
		for ( const id of [ 'field:nights', 'serial' ] ) {
			const spec = filterSpec( by( id ) );
			expect( spec.filterBy.operators ).toEqual( [ 'is', 'lessThan', 'greaterThan', 'between' ] );
			expect( spec.type ).toBe( 'number' );
			expect( spec.getValueFormatted( { item: { x: 2.5 }, field: { getValue: ( { item } ) => item.x } } ) ).toBe( '2,5' );
			expect( spec.getValueFormatted( { item: { x: 3 }, field: { getValue: ( { item } ) => item.x } } ) ).toBe( '3' );
		}
	} );

	it( 'gives no „Nincs válasz” to a text, a number or a date column (deferred)', () => {
		for ( const id of [ 'code', 'field:note', 'field:nights', 'field:born', 'serial' ] ) {
			expect( JSON.stringify( filterSpec( { ...by( id ), empty: true } ) ) ).not.toContain( NO_ANSWER );
		}
	} );

	it( 'gives text and Kód “contains” and a date before / after', () => {
		expect( filterSpec( by( 'code' ) ).filterBy.operators ).toEqual( [ 'contains' ] );
		expect( filterSpec( by( 'field:note' ) ).filterBy.operators ).toEqual( [ 'contains' ] );
		expect( filterSpec( by( 'field:born' ) ).filterBy.operators ).toEqual( [ 'before', 'after' ] );
	} );
} );

describe( 'a typed number', () => {
	it( 'reads a decimal comma and a dot alike, keeps up to eight decimals, and refuses anything else', () => {
		expect( decimal( '2,5' ) ).toBe( '2.5' );
		expect( decimal( '2.5' ) ).toBe( '2.5' );
		expect( decimal( 3.7 ) ).toBe( '3.7' );
		expect( decimal( ' -0,25 ' ) ).toBe( '-0.25' );
		expect( decimal( '0,123456789' ) ).toBe( '0.12345678' );
		expect( decimal( '10' ) ).toBe( '10' );
		expect( decimal( '-0' ) ).toBe( '0' );
		for ( const bad of [ '', 'abc', '1,2,3', undefined, null, NaN ] ) {
			expect( decimal( bad ) ).toBe( '' );
		}
	} );
} );

describe( 'what is sent to the server', () => {
	it( 'drops a filter that has no value yet, and trims the search', () => {
		const view = {
			filters: [
				{ field: 'field:lunch', operator: 'isAny', value: undefined },
				{ field: 'field:note', operator: 'contains', value: '   ' },
				{ field: 'status', operator: 'isAny', value: [] },
				{ field: 'code', operator: 'contains', value: ' 0a ' },
			],
			search: '  Ödön ',
		};
		expect( narrowOf( view, columns ) ).toEqual( {
			filters: [ { field: 'code', operator: 'contains', value: '0a' } ],
			search: 'Ödön',
		} );
	} );

	it( 'keeps false: a filter for “no” narrows, an unset one does not', () => {
		const sent = narrowOf( { filters: [ { field: 'field:photo', operator: 'is', value: false } ] }, columns );
		expect( sent.filters ).toEqual( [ { field: 'field:photo', operator: 'is', value: false } ] );
		expect( narrowOf( { filters: [ { field: 'field:photo', operator: 'is', value: undefined } ] }, columns ).filters ).toEqual( [] );
	} );

	it( 'sends a number as a decimal, and a range only with both ends', () => {
		const view = ( operator, value ) => ( { filters: [ { field: 'field:nights', operator, value } ] } );
		expect( narrowOf( view( 'is', 3.7 ), columns ).filters[ 0 ].value ).toBe( '3.7' );
		expect( narrowOf( view( 'greaterThan', '2,5' ), columns ).filters[ 0 ].value ).toBe( '2.5' );
		expect( narrowOf( view( 'between', [ 2, '' ] ), columns ).filters ).toEqual( [] );
		expect( narrowOf( view( 'between', [ 2, 5.5 ] ), columns ).filters[ 0 ].value ).toEqual( [ '2', '5.5' ] );
		// Szám takes the same operators and decimals.
		const serial = ( operator, value ) => ( { filters: [ { field: 'serial', operator, value } ] } );
		expect( narrowOf( serial( 'between', [ '1', '50' ] ), columns ).filters[ 0 ].value ).toEqual( [ '1', '50' ] );
		expect( narrowOf( serial( 'lessThan', 4 ), columns ).filters ).toEqual( [ { field: 'serial', operator: 'lessThan', value: '4' } ] );
	} );

	it( 'sends „Nincs válasz” as a value of the column’s own filter, and the trimmed search', () => {
		const view = { filters: [ { field: 'field:lunch', operator: 'isAny', value: [ 'veg', NO_ANSWER ] } ], search: ' ors ' };
		expect( narrowOf( view, columns ) ).toEqual( {
			filters: [ { field: 'field:lunch', operator: 'isAny', value: [ 'veg', NO_ANSWER ] } ],
			search: 'ors',
		} );
		const yesNo = { filters: [ { field: 'field:photo', operator: 'is', value: NO_ANSWER } ] };
		expect( narrowOf( yesNo, columns ).filters ).toEqual( [ { field: 'field:photo', operator: 'is', value: NO_ANSWER } ] );
	} );

	it( 'gives the view\'s own condition as a locked DataViews filter that is never sent', () => {
		const condition = { text: 'Állapot: Rögzített', terms: 1 };
		const field = conditionField( condition );
		// DataViews shows a locked filter with no value as its name: the condition's own text, no „Feltétel” word.
		expect( field.label ).toBe( 'Állapot: Rögzített' );
		expect( field.elements.length ).toBeGreaterThan( 0 );
		expect( conditionField( null ) ).toBeNull();
		expect( lockedFilters( null ) ).toEqual( [] );
		const locked = lockedFilters( condition );
		expect( locked ).toEqual( [ { field: CONDITION, operator: 'isAny', value: undefined, isLocked: true } ] );
		expect( narrowOf( { filters: locked, search: '' }, columns ) ).toEqual( { filters: [], search: '' } );
		expect( isNarrowed( narrowOf( { filters: locked, search: '' }, columns ) ) ).toBe( false );
	} );

	it( 'says whether anything narrows, and which columns are filtered', () => {
		expect( isNarrowed( narrowOf( { filters: [], search: '' }, columns ) ) ).toBe( false );
		expect( isNarrowed( narrowOf( { filters: [], search: 'x' }, columns ) ) ).toBe( true );
		const narrow = narrowOf( { filters: [ { field: 'code', operator: 'contains', value: 'a' } ] }, columns );
		expect( isNarrowed( narrow ) ).toBe( true );
		expect( [ ...filteredIds( narrow ) ] ).toEqual( [ 'code' ] );
	} );
} );
