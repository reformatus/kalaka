/**
 * The status acts of the detail panel (S39 on #247): the acts row from `can` only, each dialog (the ability and
 * input it runs, the reason rule, the preview warning of admit and restore, no money section), a typed refusal
 * shown with the server's text, the generic failure for the rest, and what is handed to `onDone`.
 */
import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';
import StatusActDialog from '../../src/Admin/app/grid/StatusActDialog';
import StatusActs from '../../src/Admin/app/grid/StatusActs';
import { abilityOf, actsFor, failureOf, goesOver, inputOf, staleAfter } from '../../src/Admin/app/grid/statusActs';
import { CONFIRM, MONEY, STATUS_ACTS } from '../../src/Admin/app/grid/texts';

jest.mock( '@wordpress/components', () => {
	const { createElement: h } = require( 'react' );
	const TextareaControl = ( { label, value, onChange, help } ) =>
		h( 'label', null, label, h( 'textarea', { 'aria-label': label, value, onChange: ( e ) => onChange( e.target.value ) } ), help );
	const Button = ( { children, ...rest } ) => {
		delete rest.isBusy;
		return h( 'button', rest, children );
	};
	const Modal = ( { title, children } ) => h( 'div', { role: 'dialog' }, h( 'h1', null, title ), children );
	const Notice = ( { children, status } ) => h( 'div', { 'data-notice': status }, children );
	return { Button, Modal, Notice, TextareaControl, SnackbarList: () => null };
} );

jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: jest.fn() } ), { virtual: true } );

window.IS_REACT_ACT_ENVIRONMENT = true;

let host;
let root;
let done;
let reload;
const config = { rest: '', event: 7 };
const OVER = { changed: true, status: 'registered', over_capacity: true, seats: [ { pool: 'event', field: null, outcome: 'over' } ] };
const HELD = { changed: true, status: 'registered', over_capacity: false, seats: [ { pool: 'event', field: null, outcome: 'held' } ] };

async function render( act_, preview = HELD ) {
	apiFetch.mockImplementation( ( request ) => Promise.resolve( request.data.input.preview ? preview : HELD ) );
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
	done = jest.fn().mockResolvedValue( undefined );
	reload = jest.fn();
	await act( async () => {
		root.render( createElement( StatusActDialog, { config, code: '92F7', act: act_, onClose: () => {}, onDone: done, reload } ) );
	} );
}
const textarea = () => host.querySelector( 'textarea' );
const notices = ( status ) => Array.from( host.querySelectorAll( `[data-notice="${ status }"]` ) ).map( ( n ) => n.textContent );
async function write( value ) {
	const setter = Object.getOwnPropertyDescriptor( window.HTMLTextAreaElement.prototype, 'value' ).set;
	await act( async () => {
		setter.call( textarea(), value );
		textarea().dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
}
async function submit() {
	await act( async () => {
		host.querySelector( 'form' ).dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );
	} );
}
const writes = () => apiFetch.mock.calls.map( ( call ) => call[ 0 ] ).filter( ( request ) => ! request.data.input.preview );

beforeEach( () => apiFetch.mockReset() );
afterEach( async () => {
	if ( root ) {
		await act( async () => root.unmount() );
		root = null;
		host.remove();
	}
} );

describe( 'the acts row', () => {
	test( 'shows exactly the acts whose can flag is true, in the fixed order', () => {
		expect( actsFor( { restore: true, admit: true, withdraw: false, remove: true } ) ).toEqual( [ 'admit', 'remove', 'restore' ] );
		expect( actsFor( {} ) ).toEqual( [] );
		expect( actsFor( undefined ) ).toEqual( [] );
	} );

	test( 'renders one button per act and none when stale', async () => {
		host = document.createElement( 'div' );
		document.body.appendChild( host );
		root = createRoot( host );
		const can = { withdraw: true, remove: true };
		await act( async () => root.render( createElement( StatusActs, { config, code: 'x', can, stale: false, reload: () => {}, onSaved: () => {} } ) ) );
		expect( Array.from( host.querySelectorAll( 'button' ) ).map( ( b ) => b.textContent ) ).toEqual( [ STATUS_ACTS.withdraw, STATUS_ACTS.remove ] );
		await act( async () => root.render( createElement( StatusActs, { config, code: 'x', can, stale: true, reload: () => {}, onSaved: () => {} } ) ) );
		expect( host.querySelectorAll( 'button' ) ).toHaveLength( 0 );
	} );
} );

describe( 'the call', () => {
	test( 'names the ability and the input', () => {
		expect( abilityOf( 'admit' ) ).toBe( 'kalaka/admit-registration' );
		expect( inputOf( 7, 'A1', '' ) ).toEqual( { event_id: 7, code: 'A1' } );
		expect( inputOf( 7, 'A1', 'ok', true ) ).toEqual( { event_id: 7, code: 'A1', reason: 'ok', preview: true } );
	} );
} );

describe( 'each dialog', () => {
	test.each( [ 'admit', 'withdraw', 'remove', 'restore' ] )( '%s runs its ability with the trimmed reason and hands the act on', async ( name ) => {
		await render( name );
		await write( '  Telefonon kérte  ' );
		await submit();
		expect( writes() ).toHaveLength( 1 );
		expect( writes()[ 0 ].path ).toBe( `/wp-abilities/v1/abilities/kalaka/${ name }-registration/run` );
		expect( writes()[ 0 ].data.input ).toEqual( { event_id: 7, code: '92F7', reason: 'Telefonon kérte' } );
		expect( done ).toHaveBeenCalledWith( name );
	} );

	test( 'withdraw, admit and restore send without a reason; remove needs one', async () => {
		await render( 'withdraw' );
		await submit();
		expect( writes()[ 0 ].data.input ).toEqual( { event_id: 7, code: '92F7' } );
		await act( async () => root.unmount() );
		host.remove();
		apiFetch.mockReset();
		await render( 'remove' );
		await submit();
		expect( writes() ).toHaveLength( 0 );
		expect( host.textContent ).toContain( CONFIRM.reasonEmpty );
		await write( 'a'.repeat( 501 ) );
		await submit();
		expect( writes() ).toHaveLength( 0 );
		expect( host.textContent ).toContain( CONFIRM.reasonTooLong );
	} );

	test( 'withdraw and remove show no money section and ask no preview', async () => {
		await render( 'withdraw' );
		expect( apiFetch ).not.toHaveBeenCalled();
		expect( host.textContent ).not.toMatch( /Pénzügyi|Egyenleg/ );
	} );

	test( 'admit and restore ask the preview and warn only when it says over', async () => {
		await render( 'admit', OVER );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data.input.preview ).toBe( true );
		expect( notices( 'warning' ) ).toEqual( [ STATUS_ACTS.full ] );
		await act( async () => root.unmount() );
		host.remove();
		await render( 'restore', HELD );
		expect( notices( 'warning' ) ).toEqual( [] );
	} );

	test( 'goesOver reads over_capacity and any pool over', () => {
		expect( goesOver( null ) ).toBe( false );
		expect( goesOver( HELD ) ).toBe( false );
		expect( goesOver( { over_capacity: false, seats: [ { outcome: 'over' } ] } ) ).toBe( true );
		expect( goesOver( OVER ) ).toBe( true );
	} );
} );

describe( 'a refusal', () => {
	test( 'a typed refusal shows the server text and the panel re-reads', async () => {
		await render( 'withdraw' );
		apiFetch.mockImplementation( () => Promise.reject( { code: 'kalaka_status_act_refused', message: 'A művelet nem hajtható végre.' } ) );
		await submit();
		expect( notices( 'error' ) ).toEqual( [ 'A művelet nem hajtható végre.' ] );
		expect( reload ).toHaveBeenCalled();
		expect( done ).not.toHaveBeenCalled();
	} );

	test( 'anything else is the generic failure, English or raw never shown', async () => {
		await render( 'withdraw' );
		apiFetch.mockImplementation( () => Promise.reject( { code: 'rest_forbidden', message: 'Sorry, you are not allowed.' } ) );
		await submit();
		expect( notices( 'error' ) ).toEqual( [ MONEY.saveFail ] );
		expect( reload ).not.toHaveBeenCalled();
		expect( failureOf( undefined ) ).toBe( MONEY.saveFail );
		expect( failureOf( { code: 'kalaka_not_found', message: '' } ) ).toBe( MONEY.saveFail );
		expect( staleAfter( { code: 'kalaka_version_conflict' } ) ).toBe( true );
		expect( staleAfter( { code: 'kalaka_permission_denied' } ) ).toBe( false );
	} );

	test( 'a second submit while it runs sends nothing more', async () => {
		await render( 'withdraw' );
		let release;
		apiFetch.mockImplementation( () => new Promise( ( resolve ) => ( release = resolve ) ) );
		await submit();
		await submit();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		await act( async () => release( HELD ) );
	} );
} );
