import {
	answerable,
	CONDITION,
	conditionField,
	lockedFilters,
	filteredIds,
	filterSpec,
	isNarrowed,
	narrowOf,
	UNANSWERED,
	unansweredField,
} from '../../src/Admin/app/grid/narrow';

const columns = [
	{ column: 'serial', label: 'Szám', type: 'serial', numeric: true },
	{ column: 'code', label: 'Kód', type: 'code' },
	{ column: 'status', label: 'Állapot', type: 'status', members: [ { value: 'registered', label: 'Rögzítve' } ] },
	{ column: 'field:lunch', label: 'Ebéd', type: 'choice', members: [ { value: 'veg', label: 'Vegetáriánus' } ] },
	{ column: 'field:photo', label: 'Fénykép', type: 'consent', options: { 1: 'Igen', 0: 'Nem' } },
	{ column: 'field:nights', label: 'Éjszakák', type: 'number', numeric: true },
	{ column: 'field:born', label: 'Születési idő', type: 'date' },
	{ column: 'decision:confirmation', label: 'Visszaigazolás', type: 'decision', states: { confirmed: 'Visszaigazolva' } },
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
		] );
		expect( filterSpec( by( 'decision:late_fee' ) ).filterBy.operators ).toEqual( [ 'contains' ] );
	} );

	it( 'keeps yes, no and no answer apart: true and false are the two elements, is / isNot', () => {
		const spec = filterSpec( by( 'field:photo' ) );
		expect( spec.elements ).toEqual( [
			{ value: true, label: 'Igen' },
			{ value: false, label: 'Nem' },
		] );
		expect( spec.filterBy.operators ).toEqual( [ 'is', 'isNot' ] );
	} );

	it( 'gives a display-only time column no filter and no sort, and any other unknown type text', () => {
		expect( filterSpec( { column: 'registered_at', label: 'Rögzítve', type: 'time', sortable: true } ) ).toEqual( {
			type: 'text',
			filterBy: false,
			enableSorting: false,
		} );
		expect( filterSpec( { column: 'x', label: 'X', type: 'other' } ).filterBy.operators ).toEqual( [ 'contains' ] );
	} );

	it( 'gives every number column from–to beside =, < and >, and Szám only from–to', () => {
		expect( filterSpec( by( 'field:nights' ) ).filterBy.operators ).toEqual( [
			'is',
			'lessThan',
			'greaterThan',
			'between',
		] );
		expect( filterSpec( by( 'serial' ) ).filterBy.operators ).toEqual( [ 'between' ] );
	} );

	it( 'gives text and Kód “contains” and a date before / after', () => {
		expect( filterSpec( by( 'code' ) ).filterBy.operators ).toEqual( [ 'contains' ] );
		expect( filterSpec( by( 'field:note' ) ).filterBy.operators ).toEqual( [ 'contains' ] );
		expect( filterSpec( by( 'field:born' ) ).filterBy.operators ).toEqual( [ 'before', 'after' ] );
	} );
} );

describe( '„Nincs válasz”', () => {
	it( 'is one filter over the field and decision columns, with the one operator isAny', () => {
		expect( answerable( columns ).map( ( c ) => c.column ) ).toEqual( [
			'field:lunch',
			'field:photo',
			'field:nights',
			'field:born',
			'decision:confirmation',
			'decision:late_fee',
			'field:note',
		] );
		const field = unansweredField( columns );
		expect( field.id ).toBe( UNANSWERED );
		expect( field.filterBy.operators ).toEqual( [ 'isAny' ] );
		// A plain option under „Szűrő hozzáadása”, not pinned: no chip until it is chosen.
		expect( field.filterBy.isPrimary ).toBeUndefined();
		expect( field.elements ).toContainEqual( { value: 'field:lunch', label: 'Ebéd' } );
		expect( field.elements.some( ( e ) => e.value === 'code' || e.value === 'status' ) ).toBe( false );
	} );

	it( 'is none for a view with no field or decision column', () => {
		expect( unansweredField( columns.slice( 0, 3 ) ) ).toBeNull();
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

	it( 'cuts a number to the whole number its chip shows, and sends a range only with both ends', () => {
		const view = ( operator, value ) => ( { filters: [ { field: 'field:nights', operator, value } ] } );
		expect( narrowOf( view( 'is', 3.7 ), columns ).filters[ 0 ].value ).toBe( 3 );
		expect( narrowOf( view( 'between', [ 2, '' ] ), columns ).filters ).toEqual( [] );
		expect( narrowOf( view( 'between', [ 2, 5 ] ), columns ).filters[ 0 ].value ).toEqual( [ 2, 5 ] );
		expect( narrowOf( { filters: [ { field: 'serial', operator: 'between', value: [ '1', '50' ] } ] }, columns ).filters[ 0 ].value ).toEqual( [ 1, 50 ] );
	} );

	it( 'sends „Nincs válasz” with its columns, and the trimmed search', () => {
		const view = { filters: [ { field: UNANSWERED, operator: 'isAny', value: [ 'field:note' ] } ], search: ' ors ' };
		expect( narrowOf( view, columns ) ).toEqual( {
			filters: [ { field: UNANSWERED, operator: 'isAny', value: [ 'field:note' ] } ],
			search: 'ors',
		} );
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
		const none = narrowOf( { filters: [ { field: UNANSWERED, operator: 'isAny', value: [ 'field:note' ] } ] }, columns );
		expect( [ ...filteredIds( none ) ] ).toEqual( [ 'field:note' ] );
	} );
} );
