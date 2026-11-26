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
		TextControl: ( { value, onChange, label, help } ) =>
			h( 'div', null, h( 'input', { 'aria-label': label, value, onChange: ( e ) => onChange( e.target.value ) } ), h( 'p', null, help ) ),
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
	expect( label ).not.toContain( '4. számú esemény' );
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

const type = ( input, value ) => {
	const setter = Object.getOwnPropertyDescriptor( window.HTMLInputElement.prototype, 'value' ).set;
	setter.call( input, value );
	input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
};
const press = ( host, name ) => [ ...host.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent === name );

test( 'the budget control shows the stored value and the default, and a save sends the whole number through the ability', async () => {
	apiFetch.mockReset();
	apiFetch.mockImplementation( ( { method } ) => ( method === 'POST' ? Promise.resolve( 60 ) : Promise.resolve( budgetRead( {} ) ) ) );
	const host = await mount();
	const input = host.querySelector( 'input[aria-label="Óránként legfeljebb küldhető levél"]' );
	expect( input.value ).toBe( '240' );
	expect( host.textContent ).toContain( 'alapértelmezés: 240' );
	await act( async () => type( input, '60' ) );
	await act( async () => press( host, 'Mentés' ).click() );
	const post = apiFetch.mock.calls.map( ( c ) => c[ 0 ] ).find( ( c ) => c.method === 'POST' );
	expect( post.path ).toBe( '/wp-abilities/v1/abilities/kalaka/set-mail-budget/run' );
	expect( post.data ).toEqual( { input: { hourly_budget: 60 } } );
} );

test( 'a refused budget shows the server\'s own text and sends the value as typed; nothing is checked on the page', async () => {
	apiFetch.mockReset();
	const text = 'Az óránként legfeljebb küldhető levelek száma 1 és 2000 közötti egész szám lehet.';
	apiFetch.mockImplementation( ( { method } ) =>
		method === 'POST' ? Promise.reject( { code: 'kalaka_mail_budget_invalid', message: text } ) : Promise.resolve( budgetRead( {} ) )
	);
	const host = await mount();
	await act( async () => type( host.querySelector( 'input' ), '5000' ) );
	await act( async () => press( host, 'Mentés' ).click() );
	const post = apiFetch.mock.calls.map( ( c ) => c[ 0 ] ).find( ( c ) => c.method === 'POST' );
	expect( post.data ).toEqual( { input: { hourly_budget: 5000 } } );
	expect( host.textContent ).toContain( text );
	expect( host.querySelector( 'input' ).value ).toBe( '5000' );
} );

test( 'without can.set_budget the budget is shown read-only: no field, no save', async () => {
	apiFetch.mockReset();
	apiFetch.mockResolvedValue( budgetRead( { can: {}, hourly_budget: 60 } ) );
	const host = await mount();
	expect( host.querySelector( 'input' ) ).toBeNull();
	expect( press( host, 'Mentés' ) ).toBeUndefined();
	expect( host.textContent ).toContain( '60' );
	expect( host.textContent ).toContain( 'alapértelmezés: 240' );
} );
