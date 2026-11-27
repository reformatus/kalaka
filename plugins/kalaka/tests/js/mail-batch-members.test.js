/**
 * A batch's card: the member list is one text per registration („00A1 · Kiss Anna – sorba került”) and can be hidden by
 * a disclosure button whose label never changes.
 */
import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { Members } from '../../src/Admin/app/mail/Batches';
import { BATCHES } from '../../src/Admin/app/mail/texts';

jest.mock( '../../src/Admin/app/mail/ListTable', () => ( { __esModule: true, default: () => null } ) );

jest.mock( '@wordpress/components', () => {
	const { createElement: h } = require( 'react' );
	return {
		Button: ( { children, ...rest } ) => {
			delete rest.variant;
			return h( 'button', rest, children );
		},
		Modal: ( { children, title } ) => h( 'div', { 'data-modal': title }, children ),
	};
} );
jest.mock( '../../src/Admin/app/mail/useRead', () => ( {
	__esModule: true,
	default: () => ( {
		loading: false,
		data: {
			subject: 'Közlemény',
			members: [
				{ code: 'A1', name: 'Kiss Anna', outcome: 'queued' },
				{ code: 'A2', name: null, outcome: 'skipped_removed' },
			],
		},
	} ),
} ) );

window.IS_REACT_ACT_ENVIRONMENT = true;

let host;
let root;
beforeEach( async () => {
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
	await act( async () => root.render( createElement( Members, { config: { rest: '/x', event: 1 }, id: 3, onClose: () => {} } ) ) );
} );
afterEach( async () => {
	await act( async () => root.unmount() );
	host.remove();
} );

const toggle = () => Array.from( host.querySelectorAll( 'button' ) ).find( ( b ) => b.textContent === BATCHES.members );
const list = () => host.querySelector( 'ul.km-members' );

test( 'each member is one text: code and name, then the outcome; without a name the code alone', () => {
	expect( list().textContent ).toContain( 'A1 · Kiss Anna – ' + BATCHES.outcome_queued );
	expect( list().textContent ).toContain( 'A2 – ' + BATCHES.outcome_skipped_removed );
	expect( list().textContent ).not.toContain( 'A2 ·' );
} );

test( 'the list is shown at first and a button hides and shows it; the label and the type never change', async () => {
	expect( list().hidden ).toBe( false );
	expect( toggle().getAttribute( 'aria-expanded' ) ).toBe( 'true' );
	expect( toggle().getAttribute( 'aria-controls' ) ).toBe( list().id );
	await act( async () => toggle().click() );
	expect( list().hidden ).toBe( true );
	expect( toggle().getAttribute( 'aria-expanded' ) ).toBe( 'false' );
	expect( toggle().textContent ).toBe( BATCHES.members );
	await act( async () => toggle().click() );
	expect( list().hidden ).toBe( false );
	expect( toggle().textContent ).toBe( BATCHES.members );
} );
