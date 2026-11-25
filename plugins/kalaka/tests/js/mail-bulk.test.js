/**
 * The grid's bulk action „Levél küldése”: eligible only on a tab that carries the manual mail, and its dialog asks
 * about the codes of the ticked rows. The real dialog with small stand-ins, as `mail-manual-dialog.test.js`.
 */
import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';
import { manualMailAction } from '../../src/Admin/app/mail/bulk';

jest.mock( '@wordpress/components', () => {
	const { createElement: h } = require( 'react' );
	const Button = ( { children, ...rest } ) => {
		delete rest.isBusy;
		delete rest.accessibleWhenDisabled;
		delete rest.variant;
		return h( 'button', rest, children );
	};
	const CheckboxControl = ( { label, checked, onChange } ) =>
		h(
			'label',
			null,
			label,
			h( 'input', { type: 'checkbox', 'aria-label': label, checked, onChange: ( e ) => onChange( e.target.checked ) } )
		);
	const SelectControl = ( { label, value, options, onChange, help } ) =>
		h(
			'label',
			null,
			label,
			h(
				'select',
				{ 'aria-label': label, value, onChange: ( e ) => onChange( e.target.value ) },
				options.map( ( o ) => h( 'option', { key: o.value, value: o.value }, o.label ) )
			),
			help
		);
	const Notice = ( { children, status } ) => h( 'div', { 'data-notice': status }, children );
	const Spinner = () => h( 'span', null, '…' );
	const Modal = ( { children } ) => h( 'div', null, children );
	return { Button, CheckboxControl, SelectControl, Notice, Spinner, Modal };
} );

jest.mock(
	'@wordpress/api-fetch',
	() => ( { __esModule: true, default: jest.fn() } ),
	{ virtual: true }
);

window.IS_REACT_ACT_ENVIRONMENT = true;

window.IS_REACT_ACT_ENVIRONMENT = true;

let host;
let root;

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockResolvedValue( { queue: [ 'Q1' ], skipped: [], unknown: [], bodies: [] } );
} );

afterEach( async () => {
	if ( root ) {
		await act( async () => {
			root.unmount();
		} );
		root = null;
	}
	if ( host ) {
		host.remove();
		host = null;
	}
} );

describe( 'the grid\'s bulk action', () => {
	test( 'is eligible only on a tab that carries the manual mail, and only for bulk', () => {
		const on = manualMailAction( { eligible: true, event: 1, previewPath: '/p', onSent: () => {} } );
		const off = manualMailAction( { eligible: false, event: 1, previewPath: '/p', onSent: () => {} } );
		expect( on.supportsBulk ).toBe( true );
		expect( on.isEligible( {} ) ).toBe( true );
		expect( off.isEligible( {} ) ).toBe( false );
	} );

	test( 'its dialog asks about the codes of the ticked rows (the rows\' keys)', async () => {
		const action = manualMailAction( { eligible: true, event: 1, previewPath: '/kalaka-admin/v1/events/1/mail/manual/preview', onSent: () => {} } );
		host = document.createElement( 'div' );
		document.body.appendChild( host );
		root = createRoot( host );
		await act( async () => {
			root.render( createElement( action.RenderModal, { items: [ { key: 'Q1' }, { key: 'Q2' } ], closeModal: () => {} } ) );
		} );
		const asked = apiFetch.mock.calls.map( ( c ) => c[ 0 ] ).find( ( c ) => c.path.endsWith( '/preview' ) );
		expect( asked.data ).toEqual( { codes: [ 'Q1', 'Q2' ] } );
	} );
} );

test( 'the selection is cleared when the dialog closes after a send, not while the answer is on screen', async () => {
	apiFetch.mockImplementation( ( options ) =>
		Promise.resolve(
			options.path.endsWith( '/preview' )
				? { queue: [ 'Q1' ], skipped: [], unknown: [], bodies: [ { id: 5, subject: 'Levél' } ] }
				: options.path.endsWith( '/mail' )
				? { paused: null, held: false }
				: { batch_id: 1, queued: 1, skipped: [], repeat: false, paused: false, held: false }
		)
	);
	const done = jest.fn();
	const closeModal = jest.fn();
	const action = manualMailAction( { eligible: true, event: 1, previewPath: '/kalaka-admin/v1/events/1/mail/manual/preview', onSent: done } );
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
	await act( async () => {
		root.render( createElement( action.RenderModal, { items: [ { key: 'Q1' } ], closeModal } ) );
	} );
	await act( async () => {
		const select = host.querySelector( 'select' );
		Object.getOwnPropertyDescriptor( window.HTMLSelectElement.prototype, 'value' ).set.call( select, '5' );
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );
	await act( async () => {
		host.querySelector( 'form' ).dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );
	} );
	expect( done ).not.toHaveBeenCalled();
	await act( async () => {
		host.querySelector( 'button' ).click();
	} );
	expect( closeModal ).toHaveBeenCalled();
	// DataViews unmounts the modal on closeModal; the selection goes with it, once.
	await act( async () => root.unmount() );
	root = null;
	expect( done ).toHaveBeenCalledTimes( 1 );
} );

test( 'a dialog closed any other way after a send (×, Esc: DataViews unmounts it) clears the selection too', async () => {
	apiFetch.mockImplementation( ( options ) =>
		Promise.resolve(
			options.path.endsWith( '/preview' )
				? { queue: [ 'Q1' ], skipped: [], unknown: [], bodies: [ { id: 5, subject: 'Levél' } ] }
				: options.path.endsWith( '/mail' )
				? { paused: null, held: false }
				: { batch_id: 1, queued: 1, skipped: [], repeat: false, paused: false, held: false }
		)
	);
	const done = jest.fn();
	const action = manualMailAction( { eligible: true, event: 1, previewPath: '/kalaka-admin/v1/events/1/mail/manual/preview', onSent: done } );
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
	await act( async () => {
		root.render( createElement( action.RenderModal, { items: [ { key: 'Q1' } ], closeModal: () => {} } ) );
	} );
	await act( async () => {
		const select = host.querySelector( 'select' );
		Object.getOwnPropertyDescriptor( window.HTMLSelectElement.prototype, 'value' ).set.call( select, '5' );
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );
	await act( async () => {
		host.querySelector( 'form' ).dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );
	} );
	expect( done ).not.toHaveBeenCalled();
	await act( async () => root.unmount() );
	root = null;
	expect( done ).toHaveBeenCalledTimes( 1 );
} );

test( 'a dialog that sent nothing leaves the selection alone when it goes away', async () => {
	const done = jest.fn();
	const action = manualMailAction( { eligible: true, event: 1, previewPath: '/kalaka-admin/v1/events/1/mail/manual/preview', onSent: done } );
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
	await act( async () => {
		root.render( createElement( action.RenderModal, { items: [ { key: 'Q1' } ], closeModal: () => {} } ) );
	} );
	await act( async () => root.unmount() );
	root = null;
	expect( done ).not.toHaveBeenCalled();
} );
