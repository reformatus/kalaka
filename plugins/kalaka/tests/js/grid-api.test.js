import apiFetch from '@wordpress/api-fetch';
import {
	deleteView,
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
	test( 'a new view runs kalaka/save-view with the event and no id', async () => {
		apiFetch.mockResolvedValue( 3 );
		const columns = [ { column: 'code', write: false } ];
		expect(
			await saveView( config, 0, {
				name: 'Új nézet',
				filter: [],
				columns,
			} )
		).toBe( 3 );
		expect( apiFetch ).toHaveBeenCalledWith(
			call( 'kalaka/save-view', {
				event_id: 7,
				name: 'Új nézet',
				filter: [],
				columns,
			} )
		);
	} );

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

	test( 'a delete runs kalaka/delete-view and answers its { id }', async () => {
		apiFetch.mockResolvedValue( { id: 4 } );
		expect( await deleteView( config, 4, 2 ) ).toEqual( { id: 4 } );
		expect( apiFetch ).toHaveBeenCalledWith(
			call( 'kalaka/delete-view', { id: 4, version: 2 } )
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
			await saveCounts( config, 0, 'status', [ 'waitlisted' ] )
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
		await saveCounts( config, 4, 'field:lunch', [] );
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
