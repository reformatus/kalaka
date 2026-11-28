import apiFetch from '@wordpress/api-fetch';
import {
	detailProblem,
	fetchDetail,
	fetchPage,
	fetchSums,
	recordEntry,
	runAbility,
} from '../../src/Admin/app/grid/api';
import * as api from '../../src/Admin/app/grid/api';

// WordPress gives the script `wp-api-fetch`; it is not installed here.
jest.mock( '@wordpress/api-fetch', () => jest.fn(), { virtual: true } );

const config = { event: 7, rest: '/kalaka-admin/v1/events/7' };
const can = { record_money: true, record_charge: true, record_discount: true };
const call = ( name, input ) => ( {
	path: `/wp-abilities/v1/abilities/${ name }/run`,
	method: 'POST',
	data: { input },
} );

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

describe( 'the detail panel\'s calls', () => {
	test( 'the detail is read by the code', async () => {
		apiFetch.mockResolvedValue( { registration: {} } );
		await fetchDetail( config, '02S2' );
		expect( apiFetch ).toHaveBeenCalledWith( { path: '/kalaka-admin/v1/events/7/registrations/02S2' } );
	} );

	test( 'a payment runs kalaka/record-payment with the event, the currency and its key', async () => {
		apiFetch.mockResolvedValue( { id: 9 } );
		const result = await recordEntry( config, {
			kind: 'payment',
			code: '02S2',
			currency: 'HUF',
			payer: 'participant',
			amount: '1000.50',
			valueDate: '2026-11-19',
			method: 'cash',
			note: '',
			key: 'ui-abc',
		}, can );
		expect( result ).toEqual( { id: 9 } );
		expect( apiFetch ).toHaveBeenCalledWith(
			call( 'kalaka/record-payment', {
				event_id: 7,
				code: '02S2',
				payer: 'participant',
				amount: '1000.50',
				currency: 'HUF',
				method: 'cash',
				value_date: '2026-11-19',
				idempotency_key: 'ui-abc',
			} )
		);
	} );

	test( 'a refund runs kalaka/record-refund with a method and a date, and no correction flag', async () => {
		apiFetch.mockResolvedValue( { id: 10 } );
		await recordEntry( config, {
			kind: 'refund',
			code: '02S2',
			currency: 'HUF',
			payer: 'participant',
			amount: '500',
			valueDate: '2026-11-19',
			method: 'card_terminal',
			note: 'Téves befizetés',
			direction: null,
			key: 'ui-def',
		}, can );
		expect( apiFetch.mock.calls[ 0 ][ 0 ] ).toEqual(
			call( 'kalaka/record-refund', {
				event_id: 7,
				code: '02S2',
				payer: 'participant',
				amount: '500',
				currency: 'HUF',
				method: 'card_terminal',
				value_date: '2026-11-19',
				idempotency_key: 'ui-def',
				note: 'Téves befizetés',
			} )
		);
	} );

	test( 'a correction runs kalaka/record-correction with a direction and a reason, no method, no date', async () => {
		apiFetch.mockResolvedValue( { id: 11 } );
		await recordEntry( config, {
			kind: 'correction',
			code: '02S2',
			currency: 'HUF',
			payer: 'participant',
			amount: '500',
			valueDate: null,
			method: null,
			note: 'Elírás',
			direction: 'out',
			key: 'ui-ghi',
		}, can );
		expect( apiFetch.mock.calls[ 0 ][ 0 ] ).toEqual(
			call( 'kalaka/record-correction', {
				event_id: 7,
				code: '02S2',
				payer: 'participant',
				amount: '500',
				currency: 'HUF',
				idempotency_key: 'ui-ghi',
				direction: 'out',
				reason: 'Elírás',
			} )
		);
	} );
} );

describe( 'what a failed detail read means', () => {
	test( 'a known code outside the views is no access, an unknown one is not found', () => {
		expect( detailProblem( { code: 'kalaka_registration_not_in_view', data: { status: 403 } } ) ).toBe( 'noAccess' );
		expect( detailProblem( { code: 'kalaka_not_found', data: { status: 404 } } ) ).toBe( 'missing' );
		expect( detailProblem( { data: { status: 500 } } ) ).toBe( 'failed' );
		expect( detailProblem( new Error( 'x' ) ) ).toBe( 'failed' );
		expect( detailProblem( null ) ).toBe( 'failed' );
	} );
} );
