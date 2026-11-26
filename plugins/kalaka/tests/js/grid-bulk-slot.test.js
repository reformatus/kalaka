/**
 * The bulk slot: a button per bulk action, acting on the ticked rows or, with nothing ticked, on all matching rows (disabled
 * only when none match), the selection taken at the press; an action without `supportsBulk` has no button.
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

test( 'with nothing selected the button acts on all matching rows, and is disabled only when none match', async () => {
	const action = { id: 'a', label: 'Küldés', supportsBulk: true, callback: jest.fn() };
	const render2 = ( rows, props ) => act( async () => root.render( createElement( BulkSlot, { actions: [ action ], rows, ...props } ) ) );
	await render2( [], { total: 0 } );
	expect( host.querySelector( 'button' ).disabled ).toBe( true );
	await render2( [], { total: 612 } );
	expect( host.querySelector( 'button' ).disabled ).toBe( false );
	await render2( [ { key: 'K1' } ], { total: 0 } );
	expect( host.querySelector( 'button' ).disabled ).toBe( false );
} );

test( 'the words say „all matching” with nothing ticked, and „narrowed” while a filter or search is on', async () => {
	const action = { id: 'a', label: 'Küldés', labelAll: 'Mindenkinek', labelNarrowed: 'A találatoknak', supportsBulk: true, callback: jest.fn() };
	const render2 = ( rows, props ) => act( async () => root.render( createElement( BulkSlot, { actions: [ action ], rows, total: 9, ...props } ) ) );
	await render2( [] );
	expect( host.querySelector( 'button' ).textContent ).toBe( 'Mindenkinek' );
	await render2( [], { narrowed: true } );
	expect( host.querySelector( 'button' ).textContent ).toBe( 'A találatoknak' );
	await render2( [ { key: 'K1' } ], { narrowed: true } );
	expect( host.querySelector( 'button' ).textContent ).toBe( 'Küldés' );
} );

test( 'the press builds the selection from the rows at that moment: codes when ticked, the view otherwise', async () => {
	const seen = [];
	const Dialog = ( { items, selection } ) => {
		seen.push( { items, selection } );
		return createElement( 'p' );
	};
	const action = { id: 'm', label: 'M', supportsBulk: true, RenderModal: Dialog };
	const selectionOf = ( rows ) => ( rows.length ? { codes: rows.map( ( r ) => r.key ) } : { view: 3 } );
	await act( async () => root.render( createElement( BulkSlot, { actions: [ action ], rows: [], total: 5, selectionOf } ) ) );
	await act( async () => host.querySelector( 'button' ).click() );
	expect( seen[ 0 ] ).toEqual( { items: [], selection: { view: 3 } } );
	await act( async () => root.render( createElement( BulkSlot, { actions: [ action ], rows: [ { key: 'K1' } ], total: 5, selectionOf } ) ) );
	await act( async () => host.querySelector( 'button' ).click() );
	expect( seen[ seen.length - 1 ].selection ).toEqual( { codes: [ 'K1' ] } );
} );

test( 'a callback action gets the selected rows, a modal action opens its modal with them', async () => {
	const callback = jest.fn();
	const Dialog = ( { items } ) => createElement( 'p', { id: 'dlg' }, items.map( ( i ) => i.key ).join( ',' ) );
	const rows = [ { key: 'K1' }, { key: 'K2' } ];
	await render( [ { id: 'c', label: 'C', supportsBulk: true, callback } ], rows );
	await act( async () => host.querySelector( 'button' ).click() );
	expect( callback ).toHaveBeenCalledWith( rows, { selection: { codes: [ 'K1', 'K2' ] } } );
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
	expect( callback ).toHaveBeenCalledWith( [ { key: 'K2' } ], { selection: { codes: [ 'K2' ] } } );
} );
