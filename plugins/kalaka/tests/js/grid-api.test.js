import apiFetch from '@wordpress/api-fetch';
import {
	fetchSums,
	runAbility,
	saveCounts,
	saveView,
} from '../../src/Admin/app/grid/api';
import { runPick } from '../../src/Admin/app/grid/pick';
import { serial } from '../../src/Admin/app/grid/serial';

// WordPress gives the script `wp-api-fetch`; it is not installed here.
jest.mock( '@wordpress/api-fetch', () => jest.fn(), { virtual: true } );

const config = { event: 7, rest: '/kalaka-admin/v1/events/7' };
const call = ( name, input ) => ( {
	path: `/wp-abilities/v1/abilities/${ name }/run`,
	method: 'POST',
	data: { input },
} );

beforeEach( () => {
	apiFetch.mockReset();
} );

describe( 'the grid writes through the abilities', () => {
	test( 'a stored view sends its id and version back, and counts only when given', async () => {
		apiFetch.mockResolvedValue( 4 );
		await saveView( config, 4, {
			name: 'A',
			filter: [],
			columns: [],
			counts: { status: [ 'registered' ] },
			version: 2,
		} );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data.input ).toEqual( {
			event_id: 7,
			id: 4,
			version: 2,
			name: 'A',
			filter: [],
			columns: [],
			counts: { status: [ 'registered' ] },
		} );
		apiFetch.mockClear();
		await saveView( config, 4, {
			name: 'A',
			filter: [],
			columns: [],
			version: 2,
		} );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data.input ).not.toHaveProperty(
			'counts'
		);
	} );

	test( 'a refusal rejects with the error apiFetch gives', async () => {
		const error = {
			code: 'kalaka_version_conflict',
			message: 'Valaki időközben módosította ezeket az adatokat.',
		};
		apiFetch.mockRejectedValue( error );
		await expect( runAbility( 'kalaka/save-view', {} ) ).rejects.toBe(
			error
		);
	} );

	test( 'the master view’s counts run kalaka/save-master-counts, then reload the sums', async () => {
		apiFetch
			.mockResolvedValueOnce( { id: 9 } )
			.mockResolvedValueOnce( { status: { n: 1 } } );
		expect(
			await saveCounts( config, { view: 0, grant: 0 }, 'status', [ 'waitlisted' ] )
		).toEqual( { status: { n: 1 } } );
		expect( apiFetch.mock.calls[ 0 ][ 0 ] ).toEqual(
			call( 'kalaka/save-master-counts', {
				event_id: 7,
				column: 'status',
				members: [ 'waitlisted' ],
			} )
		);
		expect( apiFetch.mock.calls[ 1 ][ 0 ] ).toEqual( {
			path: '/kalaka-admin/v1/events/7/views/0/sums',
		} );
	} );

	test( 'a view’s counts save its stored shape with the new members; none removes the column', async () => {
		const stored = {
			view: 4,
			version: 5,
			name: 'Ebédlista',
			shape: {
				filter: [ { column: 'field:lunch', in: null } ],
				columns: [],
				counts: { status: [ 'registered' ], 'field:lunch': [ 'veg' ] },
			},
		};
		apiFetch
			.mockResolvedValueOnce( { full: true, views: [ stored ] } )
			.mockResolvedValueOnce( 4 )
			.mockResolvedValueOnce( {} );
		await saveCounts( config, { view: 4, grant: 0 }, 'field:lunch', [] );
		expect( apiFetch.mock.calls[ 0 ][ 0 ] ).toEqual( {
			path: '/kalaka-admin/v1/events/7/views',
		} );
		expect( apiFetch.mock.calls[ 1 ][ 0 ] ).toEqual(
			call( 'kalaka/save-view', {
				event_id: 7,
				id: 4,
				version: 5,
				name: 'Ebédlista',
				filter: stored.shape.filter,
				columns: [],
				counts: { status: [ 'registered' ] },
			} )
		);
	} );
} );

describe( 'the sum row', () => {
	test( 'sends a grant holder\'s own counted members with the request, and none for the stored choice', async () => {
		apiFetch.mockResolvedValue( {} );
		await fetchSums( config, { view: 4, grant: 3 }, { status: [ 'waitlisted' ] } );
		const path = apiFetch.mock.calls[ 0 ][ 0 ].path;
		expect( path.startsWith( '/kalaka-admin/v1/events/7/views/4/sums?' ) ).toBe( true );
		const query = path.split( '?' )[ 1 ];
		expect( /(?:^|&)grant=3(?:&|$)/.test( query ) ).toBe( true );
		const counts = /(?:^|&)counts=([^&]*)/.exec( query );
		expect( JSON.parse( decodeURIComponent( counts[ 1 ] ) ) ).toEqual( {
			status: [ 'waitlisted' ],
		} );

		apiFetch.mockClear();
		await fetchSums( config, { view: 4, grant: 3 } );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/kalaka-admin/v1/events/7/views/4/sums?grant=3',
		} );
	} );
} );

describe( 'a pick', () => {
	const pick = ( overrides ) => {
		const apply = jest.fn();
		return [
			apply,
			{
				save: async () => 'saved',
				reload: async () => 'reloaded',
				apply,
				isShown: () => true,
				isLatest: () => true,
				...overrides,
			},
		];
	};

	test( 'shows the sum row its save answers', async () => {
		const [ apply, args ] = pick();
		await runPick( args );
		expect( apply ).toHaveBeenCalledWith( 'saved' );
	} );

	test( 'that ends after a tab switch leaves the new tab’s row alone, saved or reloaded', async () => {
		let [ apply, args ] = pick( { isShown: () => false } );
		await runPick( args );
		expect( apply ).not.toHaveBeenCalled();

		[ apply, args ] = pick( {
			isShown: () => false,
			save: async () => {
				throw new Error( 'save' );
			},
		} );
		await runPick( args );
		expect( apply ).not.toHaveBeenCalled();
	} );

	test( 'overtaken by a later pick shows nothing; a failed save shows the reloaded row', async () => {
		let [ apply, args ] = pick( { isLatest: () => false } );
		await runPick( args );
		expect( apply ).not.toHaveBeenCalled();

		[ apply, args ] = pick( {
			save: async () => {
				throw new Error( 'save' );
			},
		} );
		await runPick( args );
		expect( apply ).toHaveBeenCalledWith( 'reloaded' );
	} );
} );

describe( 'serial', () => {
	test( 'a task whose failure handling fails too never stops the next one', async () => {
		const queue = serial();
		const ran = [];
		queue( async () => {
			try {
				throw new Error( 'save' );
			} catch {
				throw new Error( 'reload' );
			}
		} );
		await queue( () => ran.push( 'next' ) );
		expect( ran ).toEqual( [ 'next' ] );
	} );
} );
