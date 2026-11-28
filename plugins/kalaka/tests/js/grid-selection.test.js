/**
 * What a bulk action acts on: the ticked rows' codes, or with nothing ticked the view with its filters and search.
 */
import { isAllMatching, isNarrowedSelection, selectionBody, selectionSignature } from '../../src/Admin/app/grid/selection';

const tab = { view: 3, grant: 5 };
const filter = { field: 'status', operator: 'isAny', value: [ 'registered' ] };

describe( 'the selection body', () => {
	test( 'ticked rows are their codes, whatever the filters are', () => {
		expect( selectionBody( [ { key: '00A1' }, { key: '00B2' } ], tab, { filters: [ filter ], search: 'kov' } ) ).toEqual( {
			codes: [ '00A1', '00B2' ],
		} );
	} );

	test( 'nothing ticked is the view and the grant with the grid\'s own filters and search', () => {
		expect( selectionBody( [], tab, { filters: [ filter ], search: 'kov' } ) ).toEqual( {
			view: 3,
			grant: 5,
			filters: [ filter ],
			search: 'kov',
		} );
	} );

	test( 'no filter and no search are left out, so the whole view is meant; a full-access tab has grant 0', () => {
		const body = selectionBody( [], { view: 0, grant: 0 }, { filters: [], search: '' } );
		expect( body ).toEqual( { view: 0, grant: 0 } );
		expect( 'filters' in body ).toBe( false );
		expect( 'search' in body ).toBe( false );
	} );
} );

describe( 'telling the forms apart', () => {
	test( 'codes are not the all-matching form, and a narrowed all-matching form is told from the whole view', () => {
		expect( isAllMatching( { codes: [ 'A' ] } ) ).toBe( false );
		expect( isAllMatching( { view: 0, grant: 0 } ) ).toBe( true );
		expect( isNarrowedSelection( { view: 0, grant: 0 } ) ).toBe( false );
		expect( isNarrowedSelection( { view: 0, grant: 0, search: 'x' } ) ).toBe( true );
		expect( isNarrowedSelection( { view: 0, grant: 0, filters: [ filter ] } ) ).toBe( true );
		expect( isNarrowedSelection( { codes: [ 'A' ] } ) ).toBe( false );
	} );

	test( 'the signature is the same for the same selection and differs for another', () => {
		expect( selectionSignature( { codes: [ 'B', 'A' ] } ) ).toBe( selectionSignature( [ 'A', 'B' ] ) );
		expect( selectionSignature( { view: 3, grant: 5, search: 'x' } ) ).toBe( selectionSignature( { view: 3, grant: 5, search: 'x' } ) );
		expect( selectionSignature( { view: 3, grant: 5, search: 'x' } ) ).not.toBe( selectionSignature( { view: 3, grant: 5, search: 'y' } ) );
		expect( selectionSignature( { view: 3, grant: 5 } ) ).not.toBe( selectionSignature( { view: 3, grant: 0 } ) );
	} );
} );
