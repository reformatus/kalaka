/**
 * The answer dialog (slice B): where focus goes (the first field on open, the field a failed check is about),
 * the checks, the double-submit guard, what it sends and what it hands to `onDone`. Like the record dialog's
 * test: the real AnswerDialog with small stand-ins for wp-components; `api.js` and `@wordpress/api-fetch` stay real.
 * Every string is unapproved until the owner ticks its box: [UNAPPROVED T7] to [UNAPPROVED T11], see `REQUESTS`.
 */
import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';
import AnswerDialog from '../../src/Admin/app/grid/AnswerDialog';
import { MONEY, REQUESTS } from '../../src/Admin/app/grid/texts';

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
					h( 'input', { type: 'radio', value: o.value, checked: selected === o.value, onChange: () => onChange( o.value ) } ),
					o.label
				)
			),
			help
		);
	const TextControl = ( { label, value, onChange, help, ...rest } ) =>
		h(
			'label',
			null,
			label,
			h( 'input', { type: 'text', 'aria-label': label, 'aria-invalid': rest[ 'aria-invalid' ], autoComplete: rest.autoComplete, value, onChange: ( e ) => onChange( e.target.value ) } ),
			help
		);
	const TextareaControl = ( { label, value, onChange, help, ...rest } ) =>
		h(
			'label',
			null,
			label,
			h( 'textarea', { 'aria-label': label, 'aria-invalid': rest[ 'aria-invalid' ], maxLength: rest.maxLength, value, onChange: ( e ) => onChange( e.target.value ) } ),
			help
		);
	const Button = ( { children, ...rest } ) => {
		delete rest.isBusy;
		delete rest.variant;
		return h( 'button', rest, children );
	};
	const Modal = ( { title, children } ) =>
		h( 'div', { role: 'dialog', 'aria-label': title }, h( 'h1', null, title ), children );
	const Notice = ( { children, status } ) => h( 'div', { 'data-notice': status }, children );
	return { Button, Modal, Notice, RadioControl, TextControl, TextareaControl };
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

const offer = { id: 'offer:church', kind: 'offer', label: 'Egyházi támogatás', state: 'awaiting', amount: null, seat_full: false };
const aid = { id: 'aid', kind: 'aid', label: 'Részvételi támogatás', state: 'awaiting', amount: null };

let host;
let root;
let done;

async function render( item ) {
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
	done = jest.fn().mockResolvedValue( undefined );
	await act( async () => {
		root.render(
			createElement( AnswerDialog, { config: { rest: '', event: 7 }, code: '92F7', item, onClose: () => {}, onDone: done } )
		);
	} );
	// The opening focus is done before anyone acts.
	await act( async () => {
		jest.advanceTimersByTime( 100 );
	} );
}

const radio = ( value ) => host.querySelector( `input[type="radio"][value="${ value }"]` );
const amountField = () => host.querySelector( 'input[type="text"]' );
const noteField = () => host.querySelector( 'textarea' );
const errorsIn = ( node ) => Array.from( node.querySelectorAll( '.kg-err' ), ( e ) => e.textContent );
const typeNote = async ( value ) => {
	const setter = Object.getOwnPropertyDescriptor( window.HTMLTextAreaElement.prototype, 'value' ).set;
	await act( async () => {
		setter.call( noteField(), value );
		noteField().dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
};

async function choose( value ) {
	await act( async () => {
		radio( value ).dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
	} );
}

async function type( input, value ) {
	const setter = Object.getOwnPropertyDescriptor( window.HTMLInputElement.prototype, 'value' ).set;
	await act( async () => {
		setter.call( input, value );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
}

async function submit() {
	await act( async () => {
		host.querySelector( 'form' ).dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );
	} );
	await act( async () => {
		jest.advanceTimersByTime( 100 );
	} );
}

beforeEach( () => {
	jest.useFakeTimers();
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
	jest.useRealTimers();
} );

describe( 'focus', () => {
	test( 'lands on the first field when the dialog opens', async () => {
		await render( offer );
		expect( document.activeElement ).toBe( radio( 'yes' ) );
	} );

	test( 'goes to the choice when none is made', async () => {
		await render( offer );
		await submit();
		expect( host.textContent ).toContain( REQUESTS.answerEmpty );
		expect( document.activeElement ).toBe( radio( 'yes' ) );
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	test( 'goes to the amount when an aid "yes" has none', async () => {
		await render( aid );
		await choose( 'yes' );
		await submit();
		expect( host.textContent ).toContain( REQUESTS.awardEmpty );
		expect( document.activeElement ).toBe( amountField() );
		expect( apiFetch ).not.toHaveBeenCalled();
	} );
} );

describe( 'the checks', () => {
	test( 'an amount of zero is refused with the money dialog\'s text', async () => {
		await render( aid );
		await choose( 'yes' );
		await type( amountField(), '0' );
		await submit();
		expect( host.textContent ).toContain( MONEY.amountBad );
		expect( apiFetch ).not.toHaveBeenCalled();
	} );
} );

describe( 'one message per field', () => {
	test( 'after a failed save, choosing "yes" leaves the choice\'s message where it was and the amount untouched', async () => {
		await render( aid );
		await submit();
		expect( amountField() ).toBeNull();
		await choose( 'yes' );
		expect( amountField().getAttribute( 'aria-invalid' ) ).toBeNull();
		expect( amountField().closest( 'label' ).textContent ).not.toContain( REQUESTS.answerEmpty );
		expect( host.textContent ).not.toContain( REQUESTS.answerEmpty );
	} );

	test( 'a message of the amount does not come back after "no" then "yes"', async () => {
		await render( aid );
		await choose( 'yes' );
		await type( amountField(), 'abc' );
		await submit();
		expect( amountField().closest( 'label' ).textContent ).toContain( MONEY.amountBad );
		await choose( 'no' );
		await choose( 'yes' );
		expect( amountField().closest( 'label' ).textContent ).not.toContain( MONEY.amountBad );
		expect( amountField().getAttribute( 'aria-invalid' ) ).toBeNull();
	} );

	test( 'the amount message stays under the amount only', async () => {
		await render( aid );
		await choose( 'yes' );
		await submit();
		expect( errorsIn( host ) ).toEqual( [ REQUESTS.awardEmpty ] );
		expect( amountField().closest( 'label' ).textContent ).toContain( REQUESTS.awardEmpty );
	} );
} );

describe( 'the note', () => {
	test( 'a yes to an offer names the note its reason and sends without one', async () => {
		await render( offer );
		await choose( 'yes' );
		expect( noteField().closest( 'div' ).parentElement.textContent ).toContain( MONEY.reasonField );
		await submit();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'has no length limit of its own and the amount field refuses autofill', async () => {
		await render( aid );
		await choose( 'yes' );
		expect( noteField().maxLength ).toBeLessThan( 0 );
		expect( amountField().getAttribute( 'autocomplete' ) ).toBe( 'off' );
	} );

	test( 'over 500 characters is refused, counted by code point, and nothing is sent', async () => {
		await render( offer );
		await choose( 'yes' );
		await typeNote( '😀'.repeat( 501 ) );
		await submit();
		expect( host.textContent ).toContain( REQUESTS.noteTooLong );
		expect( noteField().getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		expect( document.activeElement ).toBe( noteField() );
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	test( 'exactly 500 characters (1000 UTF-16 units) is sent', async () => {
		await render( offer );
		await choose( 'yes' );
		await typeNote( '😀'.repeat( 500 ) );
		await submit();
		expect( host.textContent ).not.toContain( REQUESTS.noteTooLong );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'the save', () => {
	test( 'an aid "yes" sends the amount; the dialog hands the snackbar text on', async () => {
		await render( aid );
		await choose( 'yes' );
		await type( amountField(), '12000' );
		await submit();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( JSON.stringify( apiFetch.mock.calls[ 0 ][ 0 ].data ) ).toContain( '"amount":"12000' );
		expect( done ).toHaveBeenCalledWith( REQUESTS.saved );
	} );

	test( 'an identical repeat hands on no text', async () => {
		apiFetch.mockResolvedValue( { changed: false, answer: null, over_limit: false } );
		await render( offer );
		await choose( 'no' );
		await submit();
		expect( done ).toHaveBeenCalledWith( '' );
	} );

	test( 'a second submit while the first runs sends nothing more', async () => {
		let release;
		apiFetch.mockReturnValue( new Promise( ( resolve ) => ( release = resolve ) ) );
		await render( offer );
		await choose( 'yes' );
		await typeNote( 'Telefonon megerősítve' );
		await submit();
		await submit();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		await act( async () => {
			release( { changed: true } );
		} );
		expect( done ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'a failed save keeps the dialog, the general text and what was typed, and a retry sends again', async () => {
		apiFetch.mockRejectedValueOnce( new Error( '422' ) );
		await render( aid );
		await choose( 'yes' );
		await type( amountField(), '5000' );
		await submit();
		expect( host.textContent ).toContain( MONEY.saveFail );
		expect( amountField().value ).toBe( '5000' );
		expect( done ).not.toHaveBeenCalled();
		await submit();
		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( done ).toHaveBeenCalledWith( REQUESTS.saved );
	} );
} );
