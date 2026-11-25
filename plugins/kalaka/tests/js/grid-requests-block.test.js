/**
 * The requests block of the detail panel: when it shows, the state word of each request and the button that
 * only `can.answer_request` gives. Real RequestsBlock with small stand-ins for wp-components and the dialog.
 * [UNAPPROVED N7] [UNAPPROVED N858]
 */
import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import RequestsBlock from '../../src/Admin/app/grid/RequestsBlock';
import { REQUESTS } from '../../src/Admin/app/grid/texts';

jest.mock( '@wordpress/components', () => {
	const { createElement: h } = require( 'react' );
	const Button = ( { children, onClick } ) => h( 'button', { onClick }, children );
	const SnackbarList = () => null;
	return { Button, SnackbarList };
} );
jest.mock( '../../src/Admin/app/grid/AnswerDialog', () => ( { __esModule: true, default: () => null } ) );

window.IS_REACT_ACT_ENVIRONMENT = true;

const item = ( state, label = 'Egyházi támogatás' ) => ( { id: `offer:${ state }`, kind: 'offer', label, state, amount: null } );

let host;
let root;

async function render( items, can ) {
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
	const detail = { requests: { currency: 'HUF', items }, can: { answer_request: can } };
	await act( async () => {
		root.render(
			createElement( RequestsBlock, {
				config: { rest: '', event: 7 },
				code: '92F7',
				detail,
				stale: false,
				reload: () => Promise.resolve(),
				onSaved: () => {},
			} )
		);
	} );
}

afterEach( () => {
	act( () => root.unmount() );
	host.remove();
} );

describe( 'the requests block', () => {
	test( 'an awaiting request shows its state word and, with the ability, the button', async () => {
		await render( [ item( 'awaiting' ) ], true );
		expect( host.querySelector( '.kg-requests' ).textContent ).toContain( `Egyházi támogatás: ${ REQUESTS.awaiting }` );
		expect( host.querySelector( 'button' ).textContent ).toBe( REQUESTS.answer );
	} );

	test( 'an inactive request shows its label, with no state word and no button, without the ability', async () => {
		await render( [ item( 'inactive' ) ], false );
		expect( host.querySelector( 'li' ).textContent ).toBe( 'Egyházi támogatás' );
		expect( host.textContent ).not.toContain( REQUESTS.awaiting );
		expect( host.querySelector( 'button' ) ).toBeNull();
	} );

	test( 'an answered request shows its state and no button without the ability', async () => {
		await render( [ item( 'approved' ) ], false );
		expect( host.querySelector( 'li' ).textContent ).toBe( `Egyházi támogatás: ${ REQUESTS.approved }` );
		expect( host.querySelector( 'button' ) ).toBeNull();
	} );

	test( 'no requests, no block', async () => {
		await render( [], true );
		expect( host.querySelector( '.kg-requests' ) ).toBeNull();
		expect( host.textContent ).toBe( '' );
	} );
} );
