/**
 * The „Levelezés” page's per-event rows: the name and the clear button come from the read (`name`, `can.clear_event`),
 * not from the page's own event list or an inference from `held` and `queued`.
 */
import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';
import SenderPage from '../../src/Admin/app/mail/SenderPage';

jest.mock( '@wordpress/components', () => {
	const { createElement: h } = require( 'react' );
	return {
		Button: ( { children, onClick, disabled, ...rest } ) => h( 'button', { onClick, disabled, 'aria-label': rest[ 'aria-label' ] }, children ),
		Notice: ( { children } ) => h( 'div', null, children ),
		SnackbarList: () => null,
		Modal: ( { children } ) => h( 'div', null, children ),
	};
} );
jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: jest.fn() } ), { virtual: true } );
jest.mock( '../../src/Admin/app/mail/ListTable', () => {
	const { createElement: h } = require( 'react' );
	return {
		__esModule: true,
		default: ( { data, fields } ) =>
			h( 'div', null, data.map( ( item ) => h( 'div', { key: item.event_id, 'data-event': item.event_id }, fields.map( ( f ) => h( 'span', { key: f.id }, f.render ? f.render( { item } ) : null ) ) ) ) ),
	};
} );
jest.mock( '../../src/Admin/app/mail/Troubled', () => ( { __esModule: true, default: () => null } ) );

window.IS_REACT_ACT_ENVIRONMENT = true;

const row = ( over ) => ( { event_id: 1, name: 'Nyári tábor', held: false, queued: 0, sent: 0, failed: 0, with_error: 0, stuck_sending: 0, can: { clear_event: false }, ...over } );

test( 'the name and the clear button come from the read, even for an event the page does not list', async () => {
	apiFetch.mockResolvedValue( {
		paused: null,
		errors_in_row: 0,
		hourly_budget: 240,
		queued: 5,
		can: {},
		events: [ row( { event_id: 1, held: true, queued: 3, can: { clear_event: true } } ), row( { event_id: 9, name: 'Régi tábor', held: true, queued: 2, can: { clear_event: false } } ) ],
	} );
	const host = document.createElement( 'div' );
	document.body.appendChild( host );
	const config = { rest: '/kalaka-admin/v1', timezone: 'UTC', events: [ { id: 1, name: 'Elavult név', url: '/e/1' } ] };
	await act( async () => createRoot( host ).render( createElement( SenderPage, { config } ) ) );
	await act( async () => {} );
	const one = host.querySelector( '[data-event="1"]' );
	const nine = host.querySelector( '[data-event="9"]' );
	expect( one.textContent ).toContain( 'Nyári tábor' );
	expect( one.textContent ).not.toContain( 'Elavult név' );
	expect( nine.textContent ).toContain( 'Régi tábor' );
	expect( one.querySelectorAll( 'button' ).length ).toBe( 1 );
	expect( nine.querySelectorAll( 'button' ).length ).toBe( 0 );
} );
