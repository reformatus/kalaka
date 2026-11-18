import apiFetch from '@wordpress/api-fetch';
import {
	deleteView,
	runAbility,
	saveCounts,
	saveView,
} from '../../src/Admin/app/grid/api';
import { serial } from '../../src/Admin/app/grid/serial';

// WordPress gives the script `wp-api-fetch`; it is not installed here.
jest.mock( '@wordpress/api-fetch', () => jest.fn(), { virtual: true } );
jest.mock( '@wordpress/i18n', () => ( { __: ( text ) => text } ), {
	virtual: true,
} );

const config = { event: 7, rest: '/kalaka-admin/v1/events/7' };
const run = ( name ) => `/wp-abilities/v1/abilities/${ name }/run`;
/** What `apiFetch` answers unparsed: a response whose body is the JSON of the value, or empty for undefined. */
const body = ( value ) => ( {
	text: async () => ( value === undefined ? '' : JSON.stringify( value ) ),
} );
const call = ( name, input ) => ( {
	path: run( name ),
	method: 'POST',
	data: { input },
	parse: false,
} );

beforeEach( () => {
	apiFetch.mockReset();
} );

describe( 'the grid writes through the abilities', () => {
	test( 'a new view runs kalaka/save-view with the event and no id', async () => {
		apiFetch.mockResolvedValue( body( 3 ) );
		expect(
			await saveView( config, 0, {
				name: 'Új nézet',
				filter: [],
				columns: [ { column: 'code', write: false } ],
			} )
		).toBe( 3 );
		expect( apiFetch ).toHaveBeenCalledWith(
			call( 'kalaka/save-view', {
				event_id: 7,
				name: 'Új nézet',
				filter: [],
				columns: [ { column: 'code', write: false } ],
			} )
		);
	} );

	test( 'a stored view sends its id and version back, and counts only when given', async () => {
		apiFetch.mockResolvedValue( body( 4 ) );
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
	} );

	test( 'a delete runs kalaka/delete-view, whose empty answer is null', async () => {
		apiFetch.mockResolvedValue( body( undefined ) );
		expect( await deleteView( config, 4, 2 ) ).toBeNull();
		expect( apiFetch ).toHaveBeenCalledWith(
			call( 'kalaka/delete-view', { id: 4, version: 2 } )
		);
	} );

	test( 'a refusal rejects with its error, as the dialog shows it', async () => {
		const error = {
			code: 'kalaka_version_conflict',
			message: 'Valaki időközben módosította ezeket az adatokat.',
		};
		apiFetch.mockRejectedValue( { json: async () => error } );
		await expect( runAbility( 'kalaka/save-view', {} ) ).rejects.toEqual(
			error
		);
		apiFetch.mockRejectedValue( {
			json: async () => JSON.parse( '<html>' ),
		} );
		await expect( runAbility( 'kalaka/save-view', {} ) ).rejects.toEqual( {
			code: 'invalid_json',
			message: 'The response is not a valid JSON response.',
		} );
		const offline = { code: 'fetch_error', message: 'offline' };
		apiFetch.mockRejectedValue( offline );
		await expect( runAbility( 'kalaka/save-view', {} ) ).rejects.toBe(
			offline
		);
	} );

	test( 'the master view’s counts run kalaka/save-master-counts, then reload the sums', async () => {
		apiFetch
			.mockResolvedValueOnce( body( true ) )
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
			.mockResolvedValueOnce( body( 4 ) )
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

test( 'an expired nonce is renewed once and the ability runs again', async () => {
	const expired = {
		json: async () => ( {
			code: 'rest_cookie_invalid_nonce',
			message: '',
		} ),
	};
	apiFetch.nonceEndpoint = '/wp-admin/admin-ajax.php?action=rest-nonce';
	apiFetch.nonceMiddleware = { nonce: 'old' };
	globalThis.fetch = jest
		.fn()
		.mockResolvedValue( { ok: true, text: async () => 'new' } );
	apiFetch
		.mockRejectedValueOnce( expired )
		.mockResolvedValueOnce( body( undefined ) );
	expect( await deleteView( config, 4, 2 ) ).toBeNull();
	expect( apiFetch.nonceMiddleware.nonce ).toBe( 'new' );
	expect( apiFetch ).toHaveBeenCalledTimes( 2 );

	apiFetch.mockReset();
	apiFetch.mockRejectedValue( expired );
	await expect( deleteView( config, 4, 2 ) ).rejects.toEqual( {
		code: 'rest_cookie_invalid_nonce',
		message: '',
	} );
	expect( apiFetch ).toHaveBeenCalledTimes( 2 );
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
