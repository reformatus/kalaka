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
		Button: ( { children, onClick, disabled, ...rest } ) => h( 'button', { onClick, disabled, type: rest.type, 'aria-label': rest[ 'aria-label' ] }, children ),
		Notice: ( { children } ) => h( 'div', null, children ),
		SnackbarList: () => null,
		Modal: ( { children } ) => h( 'div', null, children ),
		TextControl: ( { value, onChange, help, id, ...rest } ) =>
			h( 'div', null, h( 'input', { id, value, onChange: ( e ) => onChange( e.target.value ), ...Object.fromEntries( Object.entries( rest ).filter( ( [ k ] ) => ! k.startsWith( '__' ) ) ) } ), h( 'p', { id: id + '__help' }, help ) ),
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

test( 'the clear button\'s accessible name uses the event\'s name although the read arrives after the first render', async () => {
	let answer;
	apiFetch.mockImplementation( () => new Promise( ( resolve ) => ( answer = resolve ) ) );
	const host = document.createElement( 'div' );
	document.body.appendChild( host );
	const config = { rest: '/kalaka-admin/v1', timezone: 'UTC', events: [ { id: 4, name: 'Tavaszi találkozó', url: '/e/4' } ] };
	await act( async () => createRoot( host ).render( createElement( SenderPage, { config } ) ) );
	expect( host.querySelector( 'button' ) ).toBeNull();
	await act( async () => {
		answer( {
			paused: null,
			errors_in_row: 0,
			hourly_budget: 240,
			queued: 4,
			can: {},
			events: [ row( { event_id: 4, name: 'Tavaszi találkozó', held: true, queued: 4, can: { clear_event: true } } ) ],
		} );
	} );
	const label = host.querySelector( '[data-event="4"] button' ).getAttribute( 'aria-label' );
	expect( label ).toContain( 'Tavaszi találkozó' );
	expect( label ).not.toContain( '4. esemény' );
} );

const budgetRead = ( over ) => ( {
	paused: null,
	errors_in_row: 0,
	hourly_budget: 240,
	hourly_budget_default: 240,
	queued: 0,
	can: { set_budget: true },
	events: [],
	...over,
} );
const budgetConfig = { rest: '/kalaka-admin/v1', timezone: 'UTC', events: [] };

async function mount() {
	const host = document.createElement( 'div' );
	document.body.appendChild( host );
	await act( async () => createRoot( host ).render( createElement( SenderPage, { config: budgetConfig } ) ) );
	await act( async () => {} );
	return host;
}

const labels = ( host ) => [ ...host.querySelectorAll( 'dt' ) ].map( ( dt ) => dt.textContent );

test( 'the budget is read-only text with its default for every viewer, between the errors in a row and the pending count; the form is not the page\'s', async () => {
	for ( const can of [ { set_budget: true }, {} ] ) {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( budgetRead( { can, hourly_budget: 60 } ) );
		const host = await mount();
		const names = labels( host );
		expect( names.indexOf( 'Óránként legfeljebb küldhető levél' ) ).toBe( names.indexOf( 'Egymást követő hibák száma' ) + 1 );
		expect( names.indexOf( 'Küldésre váró levelek' ) ).toBe( names.indexOf( 'Óránként legfeljebb küldhető levél' ) + 1 );
		const dd = [ ...host.querySelectorAll( 'dt' ) ].find( ( dt ) => dt.textContent === 'Óránként legfeljebb küldhető levél' ).nextElementSibling;
		expect( dd.textContent ).toBe( '60 (alapértelmezés: 240)' );
		expect( host.querySelector( 'input' ) ).toBeNull();
		expect( host.querySelector( 'form' ) ).toBeNull();
		expect( apiFetch.mock.calls.some( ( c ) => c[ 0 ].method === 'POST' ) ).toBe( false );
	}
} );
