/**
 * The registrant's withdrawal confirmation (R370): the start form opens a Modal from the server's data, the
 * Modal cancels and posts, and a form that cannot open keeps its plain GET (the no-script path).
 *
 * Small stand-ins for wp-components, as the grid's dialog tests have.
 */
import { act } from 'react';
import { init } from '../../src/Portal/Blocks/status/view';

jest.mock( '@wordpress/components', () => {
	const { createElement: h } = require( 'react' );
	const Button = ( { children, variant, __next40pxDefaultSize, ...rest } ) =>
		h(
			'button',
			{ 'data-variant': variant, 'data-40px': __next40pxDefaultSize ? 'true' : undefined, ...rest },
			children
		);
	const Modal = ( { title, onRequestClose, children } ) =>
		h(
			'div',
			{ role: 'dialog', 'aria-label': title },
			h( 'h1', null, title ),
			h( 'button', { type: 'button', 'aria-label': 'Close', onClick: onRequestClose } ),
			children
		);
	return { Button, Modal };
} );

window.IS_REACT_ACT_ENVIRONMENT = true;

const DATA = {
	question: 'Biztosan lemondod a jelentkezésedet?',
	money: 'Befizetve: 20 000 Ft. Ezt az összeget megtartjuk.',
	confirm: 'Lemondom a jelentkezést',
	cancel: 'Mégsem',
	action: 'http://example.test/wp-json/kalaka-portal/v1/withdraw',
	fields: {
		code: '12A3',
		return_url: 'http://example.test/jelentkezesem/#kalaka-withdraw-error',
		kalaka_withdraw_nonce: 'abc123',
	},
};

function page( data = DATA ) {
	document.body.innerHTML =
		'<form id="start" method="get" action="/jelentkezesem/#kalaka-withdraw-question">' +
		'<button type="submit" name="kalaka_withdraw" value="confirm">Jelentkezés lemondása</button></form>';
	if ( data !== null ) {
		document.getElementById( 'start' ).dataset.kalakaWithdraw = typeof data === 'string' ? data : JSON.stringify( data );
	}
}

async function press() {
	const event = new Event( 'submit', { bubbles: true, cancelable: true } );
	await act( async () => {
		document.getElementById( 'start' ).dispatchEvent( event );
	} );

	return event;
}

function load() {
	init();
}

const dialog = () => document.querySelector( '[role="dialog"]' );

afterEach( () => {
	document.body.innerHTML = '';
} );

test( 'the press opens the dialog from the data and does not navigate', async () => {
	page();
	load();
	const event = await press();

	expect( event.defaultPrevented ).toBe( true );
	expect( dialog().getAttribute( 'aria-label' ) ).toBe( DATA.question );
	expect( dialog().textContent ).toContain( DATA.money );
	const form = dialog().querySelector( 'form' );
	expect( form.method ).toBe( 'post' );
	expect( form.getAttribute( 'action' ) ).toBe( DATA.action );
	// Every hidden field the server named is posted, nothing else.
	expect( [ ...form.querySelectorAll( 'input[type="hidden"]' ) ].map( ( i ) => [ i.name, i.value ] ) ).toEqual(
		Object.entries( DATA.fields )
	);
	const buttons = [ ...form.querySelectorAll( 'button' ) ];
	expect( buttons.map( ( b ) => b.textContent ) ).toEqual( [ DATA.confirm, DATA.cancel ] );
	expect( buttons[ 0 ].type ).toBe( 'submit' );
	expect( buttons[ 1 ].type ).toBe( 'button' );
} );

test( 'the dialog is marked aria-modal and both buttons take the 40 px size, as the claim popup', async () => {
	page();
	load();
	await press();

	expect( dialog().getAttribute( 'aria-modal' ) ).toBe( 'true' );
	const buttons = [ ...dialog().querySelector( 'form' ).querySelectorAll( 'button' ) ];
	expect( buttons.map( ( b ) => b.dataset[ '40px' ] ) ).toEqual( [ 'true', 'true' ] );
} );

test( 'Mégsem and the close button remove the dialog and leave the page as it was', async () => {
	page();
	load();
	await press();
	await act( async () => {
		[ ...dialog().querySelectorAll( 'button' ) ].find( ( b ) => b.textContent === DATA.cancel ).click();
	} );
	expect( dialog() ).toBeNull();
	expect( document.getElementById( 'start' ) ).not.toBeNull();

	await press();
	expect( dialog() ).not.toBeNull();
	await act( async () => {
		dialog().querySelector( '[aria-label="Close"]' ).click();
	} );
	expect( dialog() ).toBeNull();
} );

test( 'no money line when the server sent none', async () => {
	page( { ...DATA, money: null } );
	load();
	await press();

	expect( dialog().querySelector( '.kalaka-withdraw-money' ) ).toBeNull();
} );

test( 'a form whose data cannot be read keeps its plain submit', async () => {
	for ( const data of [ '{broken', '{}', '[]', { question: 'x' } ] ) {
		page( data );
		load();
		const event = await press();
		expect( event.defaultPrevented ).toBe( false );
		expect( dialog() ).toBeNull();
	}
} );
