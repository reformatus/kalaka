/**
 * The confirmation row (S20): where keyboard focus goes after an act, and what it shows. The real ManualConfirmation with
 * small stand-ins for wp-components and for the dialog (which has its own test): the stand-in's one button calls
 * `onDone` as the dialog does once it is saved.
 */
import { act, createElement as h } from 'react';
import { createRoot } from 'react-dom/client';
import ManualConfirmation from '../../src/Admin/app/grid/ManualConfirmation';
import { CONFIRM, PANEL } from '../../src/Admin/app/grid/texts';

jest.mock( '@wordpress/components', () => {
	const { createElement } = require( 'react' );
	const Button = ( { children, onClick, disabled, accessibleWhenDisabled, 'aria-describedby': describedBy } ) =>
		createElement(
			'button',
			{
				onClick: disabled ? undefined : onClick,
				disabled: disabled && ! accessibleWhenDisabled,
				'aria-disabled': disabled && accessibleWhenDisabled ? 'true' : undefined,
				'aria-describedby': describedBy,
			},
			children
		);
	const SnackbarList = () => null;
	return { Button, SnackbarList };
} );

jest.mock( '../../src/Admin/app/grid/ConfirmDialog', () => {
	const { createElement } = require( 'react' );
	return {
		__esModule: true,
		default: ( { unconfirm, onDone } ) =>
			createElement( 'button', { id: 'send', onClick: () => onDone( unconfirm, { changed: true } ) }, 'send' ),
	};
} );

window.IS_REACT_ACT_ENVIRONMENT = true;

let host;
let root;

const manual = { set_at: '2026-11-20T10:00:00Z', reason: 'Telefonon', set_by: 'Teszt Elek' };
const config = { timezone: 'Europe/Budapest' };

const props = ( over ) => ( {
	config,
	code: '92F7',
	can: { confirm_manually: true, unconfirm: true },
	manual,
	warning: null,
	confirmed: true,
	stale: false,
	reload: async () => ( { registration: {}, can: {} } ),
	onSaved: () => {},
	...over,
} );

const mount = async ( p ) => {
	await act( async () => root.render( h( ManualConfirmation, p ) ) );
};
const button = ( text ) => Array.from( host.querySelectorAll( 'button' ) ).find( ( b ) => b.textContent === text );
const settle = () => act( async () => new Promise( ( resolve ) => setTimeout( resolve, 40 ) ) );

beforeEach( () => {
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
} );

afterEach( async () => {
	await act( async () => root.unmount() );
	host.remove();
} );

describe( 'focus after the act', () => {
	let close;

	beforeEach( () => {
		close = document.createElement( 'button' );
		close.textContent = 'close';
		document.body.appendChild( close );
	} );

	afterEach( () => close.remove() );

	test( 'goes back to the button that opened the dialog while it is still there', async () => {
		await mount( props( { closeRef: { current: close } } ) );
		await act( async () => button( CONFIRM.unconfirm ).click() );
		await act( async () => host.querySelector( '#send' ).click() );
		await settle();
		expect( document.activeElement ).toBe( button( CONFIRM.unconfirm ) );
	} );

	test( 'goes to the close button when the act removed the opener (an unconfirm: can.unconfirm turns false)', async () => {
		const after = props( { can: { confirm_manually: true, unconfirm: false }, manual: null, confirmed: false, closeRef: { current: close } } );
		await mount( props( { closeRef: { current: close }, reload: async () => {
			await mount( after );
			return { registration: {}, can: after.can };
		} } ) );
		await act( async () => button( CONFIRM.unconfirm ).click() );
		await act( async () => host.querySelector( '#send' ).click() );
		await settle();
		expect( button( CONFIRM.unconfirm ) ).toBeUndefined();
		expect( document.activeElement ).toBe( close );
	} );

	test( 'goes to the close button after a set, which hides the set button', async () => {
		const after = props( { can: { confirm_manually: true, unconfirm: true }, closeRef: { current: close } } );
		await mount( props( { manual: null, confirmed: false, can: { confirm_manually: true, unconfirm: false }, closeRef: { current: close }, reload: async () => {
			await mount( after );
			return { registration: {}, can: after.can };
		} } ) );
		await act( async () => button( CONFIRM.set ).click() );
		await act( async () => host.querySelector( '#send' ).click() );
		await settle();
		expect( button( CONFIRM.set ) ).toBeUndefined();
		expect( document.activeElement ).toBe( close );
	} );
} );

describe( 'the confirmation row', () => {
	test( 'holds the state and, under it, the flag lines and the buttons', async () => {
		await mount( props() );
		const row = host.querySelector( '.kg-panel-confirmation' );
		expect( row.textContent ).toContain( PANEL.confirmed );
		expect( row.querySelector( '.kg-panel-slot' ).contains( button( CONFIRM.unconfirm ) ) ).toBe( true );
	} );

	test( 'an unconfirmed registration reads „Nincs visszaigazolva” and has the set button', async () => {
		await mount( props( { confirmed: false, manual: null, can: { confirm_manually: true, unconfirm: false } } ) );
		expect( host.querySelector( '.kg-panel-confirmation' ).textContent ).toContain( PANEL.unconfirmed );
		expect( button( CONFIRM.set ) ).toBeDefined();
		expect( button( CONFIRM.unconfirm ) ).toBeUndefined();
	} );

	test( 'is left out while the last read failed, or when the read has no confirmed field', async () => {
		await mount( props( { stale: true } ) );
		expect( host.querySelector( '.kg-panel-confirmation' ) ).toBeNull();
		await mount( props( { confirmed: undefined } ) );
		expect( host.querySelector( '.kg-panel-confirmation' ) ).toBeNull();
	} );

	test( 'a row with no buttons and no flag has no slot', async () => {
		await mount( props( { manual: null, can: {} } ) );
		expect( host.querySelector( '.kg-panel-confirmation' ) ).not.toBeNull();
		expect( host.querySelector( '.kg-panel-slot' ) ).toBeNull();
	} );
} );

describe( 'the unconfirm the rules would undo at once', () => {
	const reason = 'A befizetések már elérik a fizetendő összeget, ezért a jelentkezés a visszavonás után azonnal újra visszaigazolt lesz.';

	test( 'is disabled but still focusable, with the server\'s text as visible help text and as its description', async () => {
		await mount( props( { blocked: true, warning: reason } ) );
		const unconfirm = button( CONFIRM.unconfirm );
		expect( unconfirm.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		expect( unconfirm.disabled ).toBe( false );
		expect( unconfirm.getAttribute( 'aria-describedby' ) ).toBe( 'kalaka-unconfirm-help' );
		const hint = host.querySelector( '#kalaka-unconfirm-help' );
		expect( hint.textContent ).toBe( reason );
		// Under the button, not over anything: the text is a paragraph in the row.
		expect( unconfirm.compareDocumentPosition( hint ) & Node.DOCUMENT_POSITION_FOLLOWING ).toBeTruthy();
		unconfirm.focus();
		expect( document.activeElement ).toBe( unconfirm );
	} );

	test( 'does not open the dialog', async () => {
		await mount( props( { blocked: true, warning: reason } ) );
		await act( async () => button( CONFIRM.unconfirm ).click() );
		expect( host.querySelector( '#send' ) ).toBeNull();
	} );

	test( 'a live unconfirm has no help text and is not disabled', async () => {
		await mount( props( { blocked: false, warning: null } ) );
		const unconfirm = button( CONFIRM.unconfirm );
		expect( unconfirm.getAttribute( 'aria-disabled' ) ).toBeNull();
		expect( unconfirm.getAttribute( 'aria-describedby' ) ).toBeNull();
		expect( host.querySelector( '#kalaka-unconfirm-help' ) ).toBeNull();
		await act( async () => unconfirm.click() );
		expect( host.querySelector( '#send' ) ).not.toBeNull();
	} );
} );

describe( 'the flag lines', () => {
	test( 'the reason line is left out when the reason is empty', async () => {
		await mount( props( { manual: { ...manual, reason: '' } } ) );
		expect( host.querySelector( '.kg-manual' ).textContent ).not.toContain( 'Indoklás' );
		await mount( props( { manual } ) );
		expect( host.querySelector( '.kg-manual' ).textContent ).toContain( 'Indoklás: Telefonon' );
	} );
} );
