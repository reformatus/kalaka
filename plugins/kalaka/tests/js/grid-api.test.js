import apiFetch from '@wordpress/api-fetch';
import { fetchPage, fetchSums, runAbility } from '../../src/Admin/app/grid/api';
import * as api from '../../src/Admin/app/grid/api';

// WordPress gives the script `wp-api-fetch`; it is not installed here.
jest.mock( '@wordpress/api-fetch', () => jest.fn(), { virtual: true } );

const config = { event: 7, rest: '/kalaka-admin/v1/events/7' };

beforeEach( () => {
	apiFetch.mockReset();
} );

describe( 'the grid writes nothing of the view', () => {
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

	test( 'the grid saves no view configuration: no counting save is exported', () => {
		expect( api.saveCounts ).toBeUndefined();
		expect( api.saveView ).toBeUndefined();
		expect( api.fetchViews ).toBeUndefined();
	} );
} );

describe( 'the sum row', () => {
	test( 'sends the viewer\'s own counted members with the request, and none for the stored choice', async () => {
		apiFetch.mockResolvedValue( {} );
		await fetchSums( config, { view: 4, grant: 3 }, null, { status: [ 'waitlisted' ] } );
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

describe( 'the narrowing', () => {
	test( 'the filters and the search go with the rows and with the sums', async () => {
		const narrow = { filters: [ { field: 'code', operator: 'contains', value: 'a' } ], search: 'x' };
		apiFetch.mockResolvedValue( {} );
		await fetchSums( config, { view: 0, grant: 0 }, narrow );
		const query = apiFetch.mock.calls[ 0 ][ 0 ].path.split( '?' )[ 1 ];
		expect( decodeURIComponent( /(?:^|&)filters=([^&]*)/.exec( query )[ 1 ] ) ).toBe( JSON.stringify( narrow.filters ) );
		expect( /(?:^|&)search=x(?:&|$)/.test( query ) ).toBe( true );
	} );
} );

describe( 'the rows', () => {
	test( 'Kód sorts by orderby=code in either direction', async () => {
		apiFetch.mockResolvedValue( { json: async () => [], headers: { get: () => '0' } } );
		await fetchPage( config, { view: 0, grant: 0 }, { sort: 'code', desc: true } );
		const path = apiFetch.mock.calls[ 0 ][ 0 ].path;
		expect( /(?:^|[?&])orderby=code(?:&|$)/.test( path ) ).toBe( true );
		expect( /(?:^|[?&])order=desc(?:&|$)/.test( path ) ).toBe( true );
	} );
} );
