/**
 * The manual confirmation dialog (S20): the server's warning before the unconfirm (shown or absent), the reason
 * checks, the double-submit guard (an unconfirm is a new act every time, so it is sent once), what it hands to
 * `onDone`, and that a failed save keeps the dialog with the general text (there is no refusal path).
 *
 * Like the record dialog's test: the real ConfirmDialog with small stand-ins for wp-components; `api.js` and its
 * `@wordpress/api-fetch` stay real, which is what picks the ability.
 */
import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';
import ConfirmDialog from '../../src/Admin/app/grid/ConfirmDialog';
import { CONFIRM, MONEY } from '../../src/Admin/app/grid/texts';

jest.mock( '@wordpress/components', () => {
	const { createElement: h } = require( 'react' );
	const TextareaControl = ( { label, value, onChange, help, maxLength } ) =>
		h(
			'label',
			null,
			label,
			h( 'textarea', { 'aria-label': label, value, maxLength, onChange: ( e ) => onChange( e.target.value ) } ),
			help
		);
	const Button = ( { children, ...rest } ) => {
		delete rest.isBusy;
		return h( 'button', rest, children );
	};
	const Modal = ( { title, children } ) =>
		h( 'div', { role: 'dialog', 'aria-label': title }, h( 'h1', null, title ), children );
	const Notice = ( { children, status } ) => h( 'div', { 'data-notice': status }, children );
	return { Button, Modal, Notice, TextareaControl };
} );

jest.mock(
	'@wordpress/api-fetch',
	() => ( {
		__esModule: true,
		default: jest.fn( () => Promise.resolve( { changed: true } ) ),
	} ),
	{ virtual: true }
);

window.IS_REACT_ACT_ENVIRONMENT = true;

let host;
let root;
let done;

async function render( props = {} ) {
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
	done = jest.fn().mockResolvedValue( undefined );
	await act( async () => {
		root.render(
			createElement( ConfirmDialog, {
				config: { rest: '', event: 7 },
				unconfirm: true,
				warning: null,
				code: '92F7',
				onClose: () => {},
				onDone: done,
				...props,
			} )
		);
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

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockResolvedValue( { changed: true } );
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

describe( 'the warning before the unconfirm', () => {
	test( 'the server\'s text is shown above the field, as it is', async () => {
		const text = 'A befizetések már elérik a fizetendő összeget, ezért a jelentkezés a visszavonás után azonnal újra visszaigazolt lesz.';
		await render( { warning: text } );
		expect( notices( 'warning' ) ).toEqual( [ text ] );
		expect( host.querySelector( 'h1' ).textContent ).toBe( CONFIRM.unconfirm );
	} );

	test( 'no warning, no notice', async () => {
		await render( { warning: null } );
		expect( notices( 'warning' ) ).toEqual( [] );
	} );

	test( 'the set dialog has none', async () => {
		await render( { unconfirm: false, warning: null } );
		expect( notices( 'warning' ) ).toEqual( [] );
		expect( host.querySelector( 'h1' ).textContent ).toBe( CONFIRM.set );
	} );
} );

describe( 'the reason', () => {
	test( 'is required: nothing is sent', async () => {
		await render();
		await write( '   ' );
		await submit();
		expect( apiFetch ).not.toHaveBeenCalled();
		expect( host.textContent ).toContain( CONFIRM.reasonEmpty );
		expect( done ).not.toHaveBeenCalled();
	} );

	test( 'is at most 500 characters: nothing is sent, the message says so', async () => {
		await render();
		// The textarea's own maxLength is 500, but a paste of more is checked as well.
		await write( 'a'.repeat( 501 ) );
		await submit();
		expect( apiFetch ).not.toHaveBeenCalled();
		expect( host.textContent ).toContain( CONFIRM.reasonTooLong );
	} );

	test( 'is sent trimmed', async () => {
		await render();
		await write( '  Tévedés  ' );
		await submit();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( JSON.stringify( apiFetch.mock.calls[ 0 ][ 0 ] ) ).toContain( '"reason":"Tévedés"' );
	} );
} );

describe( 'the double-submit guard', () => {
	test( 'a second submit while the unconfirm is on its way sends nothing', async () => {
		let release;
		apiFetch.mockImplementation( () => new Promise( ( resolve ) => ( release = resolve ) ) );
		await render();
		await write( 'Tévedés' );
		await submit();
		await submit();
		await submit();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		await act( async () => release( { changed: true } ) );
		expect( done ).toHaveBeenCalledTimes( 1 );
		// Still guarded after the answer, while onDone re-reads.
		await submit();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'after a failure the guard is off: the admin may send again, on purpose', async () => {
		apiFetch.mockRejectedValueOnce( new Error( 'offline' ) );
		await render();
		await write( 'Tévedés' );
		await submit();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( notices( 'error' ) ).toEqual( [ MONEY.saveFail ] );
		await submit();
		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( done ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'what the dialog hands to onDone, and failures', () => {
	test( 'the act and the ability\'s answer, for the unconfirm and for the set', async () => {
		await render();
		await write( 'Tévedés' );
		await submit();
		expect( done ).toHaveBeenCalledWith( true, { changed: true } );
		await act( async () => root.unmount() );
		host.remove();
		apiFetch.mockResolvedValue( { changed: false } );
		await render( { unconfirm: false } );
		await write( 'Telefonon' );
		await submit();
		expect( done ).toHaveBeenCalledWith( false, { changed: false } );
	} );

	test( 'a 422 is not a refusal text any more: the general failure text, the dialog and the reason stay', async () => {
		apiFetch.mockRejectedValue( { code: 'kalaka_rule_violation', message: 'A művelet nem hajtható végre.', data: { status: 422 } } );
		await render();
		await write( 'Tévedés' );
		await submit();
		expect( notices( 'error' ) ).toEqual( [ MONEY.saveFail ] );
		expect( textarea().value ).toBe( 'Tévedés' );
		expect( done ).not.toHaveBeenCalled();
	} );
} );
