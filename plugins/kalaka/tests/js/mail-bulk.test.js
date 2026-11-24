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
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( { codes: [ 'Q1', 'Q2' ] } );
	} );
} );
