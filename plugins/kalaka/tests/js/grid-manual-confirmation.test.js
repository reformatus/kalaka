/**
 * The manual confirmation block (S20): where keyboard focus goes after an act. The real ManualConfirmation with
 * small stand-ins for wp-components and for the dialog (which has its own test): the stand-in's one button calls
 * `onDone` as the dialog does once it is saved.
 */
import { act, createElement as h } from 'react';
import { createRoot } from 'react-dom/client';
import ManualConfirmation from '../../src/Admin/app/grid/ManualConfirmation';
import { CONFIRM } from '../../src/Admin/app/grid/texts';

jest.mock( '@wordpress/components', () => {
	const { createElement } = require( 'react' );
	const Button = ( { children, onClick } ) => createElement( 'button', { onClick }, children );
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
	test( 'goes back to the button that opened the dialog while it is still there (the rules re-confirmed)', async () => {
		await mount( props() );
		await act( async () => button( CONFIRM.unconfirm ).click() );
		await act( async () => host.querySelector( '#send' ).click() );
		await settle();
		expect( document.activeElement ).toBe( button( CONFIRM.unconfirm ) );
	} );

	test( 'goes to the block when the act removed the button (a plain unconfirm: can.unconfirm turns false)', async () => {
		const after = props( { can: { confirm_manually: true, unconfirm: false }, manual: null } );
		await mount( props( { reload: async () => {
			await mount( after );
			return { registration: {}, can: after.can };
		} } ) );
		await act( async () => button( CONFIRM.unconfirm ).click() );
		await act( async () => host.querySelector( '#send' ).click() );
		await settle();
		expect( button( CONFIRM.unconfirm ) ).toBeUndefined();
		expect( document.activeElement ).toBe( host.querySelector( '.kg-manual' ) );
		expect( host.querySelector( '.kg-manual' ).getAttribute( 'tabindex' ) ).toBe( '-1' );
	} );

	test( 'goes to the block after a set, which removes the set button', async () => {
		const after = props( { can: { confirm_manually: true, unconfirm: true } } );
		await mount( props( { manual: null, can: { confirm_manually: true, unconfirm: false }, reload: async () => {
			await mount( after );
			return { registration: {}, can: after.can };
		} } ) );
		await act( async () => button( CONFIRM.set ).click() );
		await act( async () => host.querySelector( '#send' ).click() );
		await settle();
		expect( button( CONFIRM.set ) ).toBeUndefined();
		expect( document.activeElement ).toBe( host.querySelector( '.kg-manual' ) );
	} );
} );
