/**
 * The „Kérelmek” tab of the detail panel: the state word of each request and the button that only
 * `can.answer_request` gives, an inactive request with „–” and no button, and the empty tab's own text. Real
 * RequestsList with small stand-ins for wp-components and the dialog. [UNAPPROVED N7] [UNAPPROVED T1308]
 */
import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { RequestsList } from '../../src/Admin/app/grid/RequestsBlock';
import { REQUESTS } from '../../src/Admin/app/grid/texts';

jest.mock( '@wordpress/components', () => {
	const { createElement: h } = require( 'react' );
	const Button = ( { children, onClick } ) => h( 'button', { onClick }, children );
	const VisuallyHidden = ( { children } ) => h( 'span', { className: 'vh' }, children );
	const SnackbarList = () => null;
	return { Button, SnackbarList, VisuallyHidden };
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
	await act( async () => {
		root.render( createElement( RequestsList, { items, currency: 'HUF', start: () => {}, can } ) );
	} );
}

afterEach( () => {
	act( () => root.unmount() );
	host.remove();
} );

describe( 'the requests tab', () => {
	test( 'an awaiting request shows its state word and, with the ability, the button', async () => {
		await render( [ item( 'awaiting' ) ], true );
		expect( host.querySelector( '.kg-requests' ).textContent ).toContain( `Egyházi támogatás: ${ REQUESTS.awaiting }` );
		expect( host.querySelector( 'button' ).textContent ).toBe( REQUESTS.answer );
	} );

	test( 'an inactive request shows „–” as its state with a hidden „Nem értelmezhető”, and no button (N1236, T1224, T1225)', async () => {
		await render( [ item( 'inactive' ) ], false );
		expect( host.querySelector( 'li span' ).textContent ).toBe( 'Egyházi támogatás: –Nem értelmezhető' );
		expect( host.querySelector( 'li [aria-hidden="true"]' ).textContent ).toBe( '–' );
		expect( host.querySelector( 'li .vh' ).textContent ).toBe( 'Nem értelmezhető' );
		expect( host.textContent ).not.toMatch( /Nem(?! értelmezhető)/ );
		expect( host.textContent ).not.toContain( REQUESTS.awaiting );
		expect( host.querySelector( 'button' ) ).toBeNull();
	} );

	test( 'an answered request shows its state and no button without the ability', async () => {
		await render( [ item( 'approved' ) ], false );
		expect( host.querySelector( 'li' ).textContent ).toBe( `Egyházi támogatás: ${ REQUESTS.approved }` );
		expect( host.querySelector( 'button' ) ).toBeNull();
	} );

	test( 'no requests: the tab says so (the tab is still there)', async () => {
		await render( [], true );
		expect( host.querySelector( '.kg-requests' ) ).toBeNull();
		expect( host.textContent ).toBe( REQUESTS.none );
	} );
} );
