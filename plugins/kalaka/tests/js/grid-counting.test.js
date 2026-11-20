import { countsToSend, createPicks, toggleMember } from '../../src/Admin/app/grid/counting';

describe( 'a counting pick', () => {
	test( 'ticks and unticks one member and keeps the others’ order', () => {
		expect( toggleMember( [], 'registered', true ) ).toEqual( [ 'registered' ] );
		expect( toggleMember( [ 'registered' ], 'waitlisted', true ) ).toEqual( [
			'registered',
			'waitlisted',
		] );
		expect( toggleMember( [ 'registered', 'waitlisted' ], 'registered', true ) ).toEqual( [
			'registered',
			'waitlisted',
		] );
		expect( toggleMember( [ 'registered', 'waitlisted' ], 'registered', false ) ).toEqual( [
			'waitlisted',
		] );
	} );

	test( 'a second tick before the first save returns includes both (the last state wins)', () => {
		const picks = createPicks();
		picks.toggle( 'status', 'registered', true, [ 'waitlisted' ] );
		picks.toggle( 'status', 'waitlisted', true, [ 'waitlisted' ] );
		expect( picks.chosen( 'status', [ 'waitlisted' ] ) ).toEqual( [
			'waitlisted',
			'registered',
		] );
	} );

	test( 'the first pick of each column starts from its own stored choice', () => {
		const picks = createPicks();
		picks.toggle( 'status', 'waitlisted', true, [ 'registered' ] );
		expect( picks.chosen( 'status', [ 'registered' ] ) ).toEqual( [
			'registered',
			'waitlisted',
		] );
		expect( picks.chosen( 'field:lunch', [ 'veg' ] ) ).toEqual( [ 'veg' ] );
	} );

	test( 'a reset forgets every pick', () => {
		const picks = createPicks();
		picks.toggle( 'status', 'waitlisted', true, [] );
		picks.reset();
		expect( picks.chosen( 'status', [ 'registered' ] ) ).toEqual( [ 'registered' ] );
		expect( picks.all() ).toEqual( {} );
	} );
} );

describe( 'what a sums request carries', () => {
	const columns = [
		{ column: 'serial' },
		{ column: 'field:lunch' },
	];

	test( 'never sends a column the view does not list, nor an empty choice', () => {
		expect(
			countsToSend(
				columns,
				{ status: [ 'registered' ], 'field:lunch': [ 'veg' ] },
				{ status: [] }
			)
		).toEqual( { 'field:lunch': [ 'veg' ] } );
	} );

	test( 'is nothing when the only stored choice is unlisted, or every pick is empty', () => {
		expect( countsToSend( columns, { status: [ 'registered' ] }, {} ) ).toBeNull();
		expect( countsToSend( columns, {}, { 'field:lunch': [] } ) ).toBeNull();
	} );

	test( 'a view without Állapot sends only its own listed choices', () => {
		expect(
			countsToSend( columns, { status: [ 'registered' ] }, { 'field:lunch': [ 'veg' ] } )
		).toEqual( { 'field:lunch': [ 'veg' ] } );
	} );
} );
