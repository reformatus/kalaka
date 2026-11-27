/**
 * The mail screens' client bits that remain after the event's „Levelek” page became PHP's: the failed list's
 * follow-up (failed rows only) and the pure helpers (a batch's subject, a body's label, the state reads). The
 * page's own render is `Admin\Mail\EventPage`, checked by PHP's integration tests, not here.
 *
 * The real components with small stand-ins for wp-components.
 */
import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';
import Troubled from '../../src/Admin/app/mail/Troubled';
import { batchSubject, bodyLabel } from '../../src/Admin/app/mail/format';
import { stateReads } from '../../src/Admin/app/mail/manual';

jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: jest.fn() } ), { virtual: true } );
// DataViews is ESM; its table is a stand-in, as the sender's test does.
jest.mock( '../../src/Admin/app/mail/ListTable', () => {
	const { createElement: h } = require( 'react' );
	return {
		__esModule: true,
		default: ( { data, fields } ) =>
			h(
				'div',
				null,
				data.map( ( item ) =>
					h(
						'div',
						{ key: item.id, 'data-row': item.code },
						fields.map( ( f ) => h( 'span', { key: f.id }, f.render ? f.render( { item } ) : null ) )
					)
				)
			),
	};
} );

jest.mock( '@wordpress/components', () => {
	const { createElement: h } = require( 'react' );
	const Button = ( { children, onClick, disabled, ...rest } ) =>
		h( 'button', { onClick, disabled, 'aria-label': rest[ 'aria-label' ] }, children );
	const Notice = ( { children } ) => h( 'div', null, children );
	return { Button, Notice };
} );

let host;
let root;

beforeEach( () => {
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
	apiFetch.mockReset();
} );

afterEach( async () => {
	await act( async () => root.unmount() );
	host.remove();
} );

describe( 'the failed list', () => {
	const rows = [
		{ id: 1, code: 'F1', status: 'failed', label: 'Jelentkezés rögzítve', attempts: 3, queued_at: '2026-11-25T10:00:00Z', last_attempt_at: null, error: 'x', batch_id: null },
		{ id: 2, code: 'P2', status: 'pending', label: 'Jelentkezés rögzítve', attempts: 1, queued_at: '2026-11-25T10:00:00Z', last_attempt_at: null, error: 'x', batch_id: null },
		{ id: 3, code: 'S3', status: 'sending', label: 'Jelentkezés rögzítve', attempts: 1, queued_at: '2026-11-25T10:00:00Z', last_attempt_at: null, error: null, batch_id: null },
	];

	test( 'only a failed delivery offers the follow-up', async () => {
		apiFetch.mockResolvedValue( rows );
		await act( async () =>
			root.render(
				createElement( Troubled, { config: { rest: '/kalaka-admin/v1', event: 1, timezone: 'UTC' } } )
			)
		);
		await act( async () => {} );
		const buttons = ( code ) => host.querySelectorAll( `[data-row="${ code }"] button` ).length;
		expect( buttons( 'F1' ) ).toBe( 1 );
		expect( buttons( 'P2' ) ).toBe( 0 );
		expect( buttons( 'S3' ) ).toBe( 0 );
	} );
} );

describe( 'helpers', () => {
	test( 'a batch whose body is gone shows the placeholder, not a subject', () => {
		expect( batchSubject( { subject: null, body_gone: true } ) ).toBe( '(törölt levélsablon)' );
		expect( batchSubject( { subject: null, body_gone: false } ) ).toBe( '(törölt levélsablon)' );
		expect( batchSubject( { subject: 'Üdv', body_gone: false } ) ).toBe( 'Üdv' );
	} );

	test( 'bodyLabel: a unique subject stays as it is, a draft gets its status in lowercase', () => {
		const all = [ { id: 1, subject: 'A', status: 'publish' }, { id: 2, subject: 'B', status: 'draft' } ];
		expect( bodyLabel( all[ 0 ], all ) ).toBe( 'A' );
		expect( bodyLabel( all[ 1 ], all ) ).toBe( 'B (vázlat)' );
		expect( bodyLabel( { id: 3, subject: 'C' }, [ { id: 3, subject: 'C' } ] ) ).toBe( 'C' );
	} );

	test( 'stateReads: the sender read and the event read, from the preview path', () => {
		expect( stateReads( '/kalaka-admin/v1/events/7/mail/manual/preview' ) ).toEqual( {
			event: '/kalaka-admin/v1/events/7/mail',
			site: '/kalaka-admin/v1/mail',
		} );
	} );
} );
