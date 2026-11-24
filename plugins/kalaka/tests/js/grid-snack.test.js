import { SNACKBAR_MS, withNotice, withoutNotice } from '../../src/Admin/app/grid/snack';
import { fmt } from '../../src/Admin/app/grid/format';
import { GRID } from '../../src/Admin/app/grid/texts';

describe( 'the copy result is a snackbar', () => {
	test( 'a success carries the count text, is spoken, and goes away by itself', () => {
		const list = withNotice( [], 'a', fmt( GRID.copied, 46 ), 'success' );
		expect( list ).toEqual( [
			{
				id: 'a',
				content: '46 sor a vágólapra másolva.',
				status: 'success',
				spokenMessage: '46 sor a vágólapra másolva.',
				explicitDismiss: false,
			},
		] );
		expect( SNACKBAR_MS ).toBe( 5000 );
	} );

	test( 'a failure says so and stays until it is dismissed', () => {
		const [ notice ] = withNotice( [], 'b', GRID.copyFail, 'error' );
		expect( notice.content ).toBe( 'A másolás nem sikerült.' );
		expect( notice.status ).toBe( 'error' );
		expect( notice.explicitDismiss ).toBe( true );
	} );

	test( 'removing one notice leaves the others', () => {
		const list = withNotice( withNotice( [], 'a', 'x', 'success' ), 'b', 'y', 'error' );
		expect( withoutNotice( list, 'a' ).map( ( n ) => n.id ) ).toEqual( [ 'b' ] );
	} );
} );
