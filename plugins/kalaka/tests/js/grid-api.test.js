import apiFetch from '@wordpress/api-fetch';
import {
	fetchAll,
	fetchPage,
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

	test( 'a failed save forgets the pick, so the menu shows the stored choice again', async () => {
		const [ apply, args ] = pick( {
			save: async () => {
				throw new Error( 'save' );
			},
			revert: jest.fn(),
		} );
		const revert = args.revert;
		await runPick( args );
		expect( revert ).toHaveBeenCalledTimes( 1 );
		expect( apply ).toHaveBeenCalledWith( 'reloaded' );
	} );

	test( 'a failed save on a tab no longer shown forgets nothing', async () => {
		const [ , args ] = pick( {
			save: async () => {
				throw new Error( 'save' );
			},
			revert: jest.fn(),
			isShown: () => false,
		} );
		const revert = args.revert;
		await runPick( args );
		expect( revert ).not.toHaveBeenCalled();
	} );

	test( 'a slow reload that ends after a tab switch leaves the new tab’s row alone', async () => {
		let shown = true;
		const apply = jest.fn();
		await runPick( {
			save: async () => 'saved',
			reload: async () => {
				shown = false;
				return 'reloaded';
			},
			apply,
			isShown: () => shown,
			isLatest: () => true,
			isFresh: () => false,
		} );
		expect( apply ).not.toHaveBeenCalled();
	} );

	test( 'a slow reload that ends after a later pick leaves that pick’s row alone', async () => {
		let latest = true;
		const apply = jest.fn();
		await runPick( {
			save: async () => {
				throw new Error( 'save' );
			},
			reload: async () => {
				latest = false;
				return 'reloaded';
			},
			apply,
			isShown: () => true,
			isLatest: () => latest,
		} );
		expect( apply ).not.toHaveBeenCalled();
	} );
} );

describe( 'the filters and the search go to the rows, the sums and the copy', () => {
	const narrow = { filters: [ { field: 'code', operator: 'contains', value: 'a' } ], search: 'ors' };
	const response = ( rows, total ) => ( {
		json: async () => rows,
		headers: { get: () => String( total ) },
	} );

	test( 'a page and the sum row carry them as query parameters; none when nothing narrows', async () => {
		apiFetch.mockResolvedValue( response( [], 0 ) );
		await fetchPage( config, { view: 3, grant: 0 }, { narrow } );
		const path = apiFetch.mock.calls[ 0 ][ 0 ].path;
		const query = new window.URLSearchParams( path.split( '?' )[ 1 ] );
		expect( JSON.parse( query.get( 'filters' ) ) ).toEqual( narrow.filters );
		expect( query.get( 'search' ) ).toBe( 'ors' );

		await fetchPage( config, { view: 3, grant: 0 }, { narrow: { filters: [], search: '' } } );
		expect( apiFetch.mock.calls[ 1 ][ 0 ].path ).not.toMatch( /filters|search/ );

		apiFetch.mockResolvedValue( {} );
		await fetchSums( config, { view: 3, grant: 0 }, narrow );
		expect( apiFetch.mock.calls[ 2 ][ 0 ].path ).toMatch( /\/views\/3\/sums\?filters=.*&search=ors$/ );
		await fetchSums( config, { view: 3, grant: 0 } );
		expect( apiFetch.mock.calls[ 3 ][ 0 ].path ).toBe( '/kalaka-admin/v1/events/7/views/3/sums' );
	} );

	test( 'a grant holder’s own counted members join the sums query', async () => {
		apiFetch.mockResolvedValue( {} );
		await fetchSums(
			config,
			{ view: 3, grant: 4 },
			narrow,
			{ status: [ 'waitlisted' ] }
		);
		const query = new window.URLSearchParams(
			apiFetch.mock.calls[ 0 ][ 0 ].path.split( '?' )[ 1 ]
		);
		expect( query.get( 'grant' ) ).toBe( '4' );
		expect( query.get( 'search' ) ).toBe( 'ors' );
		expect( JSON.parse( query.get( 'counts' ) ) ).toEqual( {
			status: [ 'waitlisted' ],
		} );
	} );

	test( 'the copy reads every page of the narrowed rows', async () => {
		apiFetch.mockResolvedValue( response( [ { key: 'a' } ], 1 ) );
		await fetchAll( { ...config, maxLimit: 500 }, { view: 3, grant: 0 }, null, false, narrow );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toMatch( /filters=/ );
	} );

	test( 'a pick saves, and its sum row is over the filters on screen', async () => {
		apiFetch.mockResolvedValue( {} );
		await saveCounts( config, { view: 0, grant: 0 }, 'status', [ 'registered' ], narrow );
		expect( apiFetch.mock.calls[ 1 ][ 0 ].path ).toMatch( /\/views\/0\/sums\?filters=/ );
	} );

	test( 'a sum row computed for filters that changed meanwhile is read again, not shown', async () => {
		const apply = jest.fn();
		await runPick( {
			save: async () => 'for the old filters',
			reload: async () => 'for the filters now on screen',
			apply,
			isShown: () => true,
			isLatest: () => true,
			isFresh: () => false,
		} );
		expect( apply ).toHaveBeenCalledWith( 'for the filters now on screen' );
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
