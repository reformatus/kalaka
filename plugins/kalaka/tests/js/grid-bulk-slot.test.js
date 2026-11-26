/**
 * The selection-only bulk slot: a button per bulk action, disabled with nothing selected, acting on the rows taken
 * at the press; an action without `supportsBulk` has no button.
 */
import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import BulkSlot from '../../src/Admin/app/grid/BulkSlot';

jest.mock( '@wordpress/components', () => {
	const { createElement: h } = require( 'react' );
	return {
		Button: ( { children, onClick, disabled } ) => h( 'button', { onClick: disabled ? undefined : onClick, disabled }, children ),
		Modal: ( { title, children } ) => h( 'div', { id: 'modal', 'data-title': title }, children ),
	};
} );

window.IS_REACT_ACT_ENVIRONMENT = true;
let root;
let host;
beforeEach( () => {
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
} );
afterEach( () => {
	act( () => root.unmount() );
	host.remove();
} );
const render = ( actions, rows ) => act( async () => root.render( createElement( BulkSlot, { actions, rows } ) ) );

test( 'a bulk action is a disabled button with nothing selected and enabled with a selection', async () => {
	const action = { id: 'a', label: 'Küldés', supportsBulk: true, callback: jest.fn() };
	await render( [ action ], [] );
	expect( host.querySelector( 'button' ).disabled ).toBe( true );
	await render( [ action ], [ { key: 'K1' } ] );
	expect( host.querySelector( 'button' ).disabled ).toBe( false );
} );

test( 'a callback action gets the selected rows, a modal action opens its modal with them', async () => {
	const callback = jest.fn();
	const Dialog = ( { items } ) => createElement( 'p', { id: 'dlg' }, items.map( ( i ) => i.key ).join( ',' ) );
	const rows = [ { key: 'K1' }, { key: 'K2' } ];
	await render( [ { id: 'c', label: 'C', supportsBulk: true, callback } ], rows );
	await act( async () => host.querySelector( 'button' ).click() );
	expect( callback ).toHaveBeenCalledWith( rows, {} );
	await render( [ { id: 'm', label: 'M', modalHeader: 'Fej', supportsBulk: true, RenderModal: Dialog } ], rows );
	await act( async () => host.querySelector( 'button' ).click() );
	expect( host.querySelector( '#modal' ).getAttribute( 'data-title' ) ).toBe( 'Fej' );
	expect( host.querySelector( '#dlg' ).textContent ).toBe( 'K1,K2' );
} );

test( 'an action that does not support bulk has no button, and the rows it is not eligible for are left out', async () => {
	await render( [ { id: 'x', label: 'X', callback: jest.fn() } ], [ { key: 'K1' } ] );
	expect( host.querySelector( 'button' ) ).toBeNull();
	const callback = jest.fn();
	await render( [ { id: 'e', label: 'E', supportsBulk: true, isEligible: ( r ) => r.key === 'K2', callback } ], [ { key: 'K1' }, { key: 'K2' } ] );
	await act( async () => host.querySelector( 'button' ).click() );
	expect( callback ).toHaveBeenCalledWith( [ { key: 'K2' } ], {} );
} );
