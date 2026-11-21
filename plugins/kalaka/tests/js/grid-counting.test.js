import {
	countsDiffer,
	countsToSend,
	createPicks,
	toggleMember,
} from '../../src/Admin/app/grid/counting';

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

	test( 'unticking the last member falls back to the stored choice, so the menu and the sum row agree', () => {
		const picks = createPicks();
		expect( picks.toggle( 'status', 'waitlisted', true, [ 'registered' ] ) ).toEqual( [
			'registered',
			'waitlisted',
		] );
		expect( picks.toggle( 'status', 'waitlisted', false, [ 'registered' ] ) ).toEqual( [
			'registered',
		] );
		expect( picks.toggle( 'status', 'registered', false, [ 'registered' ] ) ).toEqual( [
			'registered',
		] );
		expect( picks.chosen( 'status', [ 'registered' ] ) ).toEqual( [ 'registered' ] );
		expect( picks.all() ).toEqual( {} );
	} );

	test( 'unticking the last member of a column with no stored choice counts nothing', () => {
		const picks = createPicks();
		expect( picks.toggle( 'field:lunch', 'veg', true, [] ) ).toEqual( [ 'veg' ] );
		expect( picks.toggle( 'field:lunch', 'veg', false, [] ) ).toEqual( [] );
		expect( picks.chosen( 'field:lunch', [] ) ).toEqual( [] );
		expect( picks.all() ).toEqual( {} );
	} );
} );

describe( 'whether the counting differs from the default', () => {
	const columns = [ { column: 'status' }, { column: 'field:lunch' } ];
	const stored = { status: [ 'registered' ], 'field:lunch': [ 'veg' ] };

	test( 'is false with no pick, or a pick equal to the stored choice', () => {
		expect( countsDiffer( columns, stored, {} ) ).toBe( false );
		expect( countsDiffer( columns, stored, { 'field:lunch': [ 'veg' ] } ) ).toBe( false );
	} );

	test( 'is true when a pick changes the counted members', () => {
		expect( countsDiffer( columns, stored, { status: [ 'waitlisted' ] } ) ).toBe( true );
		expect( countsDiffer( columns, stored, { 'field:lunch': [] } ) ).toBe( true );
		expect( countsDiffer( columns, stored, { status: [ 'registered' ], 'field:lunch': [ 'normal' ] } ) ).toBe(
			true
		);
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
