/**
 * The merged „Tétel rögzítése” dialog (topic 6a G12): which directions it offers for each grant, that none is
 * preselected, and which ability and snackbar each direction triggers.
 *
 * Jest has no React DOM helper here, so the test renders the real RecordDialog with small stand-ins for
 * wp-components; `api.js` and its `@wordpress/api-fetch` stay real, which is what picks the ability.
 */
import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';
import RecordDialog from '../../src/Admin/app/grid/RecordDialog';
import { MONEY } from '../../src/Admin/app/grid/texts';

jest.mock( '@wordpress/components', () => {
	const { createElement: h } = require( 'react' );
	const RadioControl = ( { label, selected, options, onChange, help } ) =>
		h(
			'fieldset',
			null,
			h( 'legend', null, label ),
			options.map( ( o ) =>
				h(
					'label',
					{ key: o.value },
					h( 'input', {
						type: 'radio',
						name: label,
						value: o.value,
						checked: selected === o.value,
						onChange: () => onChange( o.value ),
					} ),
					o.label
				)
			),
			help
		);
	const TextControl = ( { label, value, onChange, help, type } ) =>
		h(
			'label',
			null,
			label,
			h( 'input', {
				'aria-label': label,
				type: type || 'text',
				value,
				onChange: ( e ) => onChange( e.target.value ),
			} ),
			help
		);
	const Button = ( { children, ...rest } ) => {
		delete rest.isBusy;
		return h( 'button', rest, children );
	};
	const Modal = ( { title, children } ) =>
		h( 'div', { role: 'dialog', 'aria-label': title }, h( 'h1', null, title ), children );
	const Notice = ( { children } ) => h( 'div', null, children );
	return { Button, Modal, Notice, RadioControl, TextControl };
} );

jest.mock(
	'@wordpress/api-fetch',
	() => ( {
		__esModule: true,
		default: jest.fn( () => Promise.resolve( { id: 1 } ) ),
	} ),
	{ virtual: true }
);

window.IS_REACT_ACT_ENVIRONMENT = true;

const money = {
	payers: [ { payer: 'participant', label: 'Jelentkező', charged: '30000', paid: '0', balance: '30000' } ],
	methods: [
		{ value: 'cash', label: 'Készpénz' },
		{ value: 'transfer', label: 'Átutalás' },
	],
	currency: 'HUF',
};

let host;
let root;
let done;

async function render( can ) {
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
	done = jest.fn().mockResolvedValue( undefined );
	await act( async () => {
		root.render(
			createElement( RecordDialog, {
				config: { timezone: 'Europe/Budapest', rest: '', event: 1 },
				mode: 'entry',
				can,
				code: 'X1',
				money,
				onClose: () => {},
				onDone: done,
			} )
		);
	} );
}

const radios = ( name ) => Array.from( host.querySelectorAll( `input[type="radio"][name="${ name }"]` ) );
const directionRadios = () => radios( MONEY.directionField );
const directionLabels = () => directionRadios().map( ( r ) => r.parentElement.textContent.trim() );

async function setValue( input, value ) {
	const setter = Object.getOwnPropertyDescriptor( window.HTMLInputElement.prototype, 'value' ).set;
	await act( async () => {
		setter.call( input, value );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
}

async function pick( input ) {
	await act( async () => {
		input.click();
	} );
}

async function submit() {
	const form = host.querySelector( 'form' );
	await act( async () => {
		form.dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );
	} );
}

beforeEach( () => {
	apiFetch.mockClear();
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

describe( 'the merged dialog\'s directions', () => {
	test( 'a holder of only record_payment sees only „Befizetés”', async () => {
		await render( { record_payment: true, record_refund: false } );
		expect( directionRadios().map( ( r ) => r.value ) ).toEqual( [ 'payment' ] );
		expect( directionLabels() ).toEqual( [ MONEY.entryIn ] );
		expect( directionRadios().some( ( r ) => r.checked ) ).toBe( false );
	} );

	test( 'a holder of only record_refund sees only „Visszafizetés”', async () => {
		await render( { record_payment: false, record_refund: true } );
		expect( directionRadios().map( ( r ) => r.value ) ).toEqual( [ 'refund' ] );
		expect( directionLabels() ).toEqual( [ MONEY.entryOut ] );
		expect( directionRadios().some( ( r ) => r.checked ) ).toBe( false );
	} );

	test( 'a holder of both sees both, and no direction is preselected', async () => {
		await render( { record_payment: true, record_refund: true } );
		expect( directionRadios().map( ( r ) => r.value ) ).toEqual( [ 'payment', 'refund' ] );
		expect( directionLabels() ).toEqual( [ MONEY.entryIn, MONEY.entryOut ] );
		expect( directionRadios().some( ( r ) => r.checked ) ).toBe( false );
	} );

	test( 'a holder of neither is offered no direction', async () => {
		await render( { record_payment: false, record_refund: false } );
		expect( directionRadios() ).toEqual( [] );
	} );
} );

describe( 'what each direction records', () => {
	async function fillAndSend( direction ) {
		await render( { record_payment: true, record_refund: true } );
		await pick( directionRadios().find( ( r ) => r.value === direction ) );
		await setValue( host.querySelector( 'input[aria-label="' + MONEY.amountField + '"]' ), '5 000' );
		await pick( radios( MONEY.methodField ).find( ( r ) => r.value === 'cash' ) );
		await submit();
	}

	test( '„Befizetés” runs kalaka/record-payment and shows „Befizetés rögzítve.”', async () => {
		await fillAndSend( 'payment' );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		const request = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( request.path ).toBe( '/wp-abilities/v1/abilities/kalaka/record-payment/run' );
		expect( request.method ).toBe( 'POST' );
		expect( request.data.input.amount ).toBe( '5000' );
		expect( request.data.input.method ).toBe( 'cash' );
		expect( request.data.input ).not.toHaveProperty( 'direction' );
		expect( request.data.input ).not.toHaveProperty( 'reason' );
		expect( done ).toHaveBeenCalledWith( MONEY.saved );
		expect( MONEY.saved ).toBe( 'Befizetés rögzítve.' );
	} );

	test( '„Visszafizetés” runs kalaka/record-refund and shows „Visszafizetés rögzítve.”', async () => {
		await fillAndSend( 'refund' );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		const request = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( request.path ).toBe( '/wp-abilities/v1/abilities/kalaka/record-refund/run' );
		expect( request.data.input.method ).toBe( 'cash' );
		expect( done ).toHaveBeenCalledWith( MONEY.savedRefund );
		expect( MONEY.savedRefund ).toBe( 'Visszafizetés rögzítve.' );
	} );
} );
