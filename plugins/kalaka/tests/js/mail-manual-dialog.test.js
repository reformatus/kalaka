/**
 * The manual mail form (piece 4): what the preview shows, that nothing is chosen for the user, that a double click and
 * a retry are the same request (one request key), that a changed body or layout is another, what each answer
 * says (queued, skipped, repeat, paused, held), and that a refusal keeps the dialog with the server's own text.
 *
 * The real form, with small stand-ins for wp-components; `api.js` stays real, so what is asserted is the REST call.
 */
import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';
import { ManualMailForm } from '../../src/Admin/app/mail/ManualMailDialog';
import { createKeys } from '../../src/Admin/app/mail/manual';
import { MANUAL, RESULT } from '../../src/Admin/app/mail/texts';
import { fmt } from '../../src/Admin/app/grid/format';

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

const PLAN = {
	queue: [ 'A1', 'A2' ],
	skipped: [ { code: 'A3', reason: 'withdrawn' }, { code: 'A4', reason: 'removed' } ],
	unknown: [],
	bodies: [ { id: 20, subject: 'Fontos közlemény' }, { id: 22, subject: 'Tudnivalók' } ],
};
const SENT = { batch_id: 3, queued: 2, skipped: [ { code: 'A3', reason: 'withdrawn' } ], repeat: false, paused: false, held: false };

let host;
let root;
let closed;
let sent;

const calls = ( path ) => apiFetch.mock.calls.map( ( c ) => c[ 0 ] ).filter( ( c ) => c.path.includes( path ) );

function route( { plan = PLAN, send = () => Promise.resolve( SENT ), site = { paused: null }, event = { held: false } } = {} ) {
	apiFetch.mockImplementation( ( options ) => {
		if ( options.path === '/kalaka-admin/v1/mail' ) {
			return site instanceof Error ? Promise.reject( site ) : Promise.resolve( site );
		}
		if ( options.path === '/kalaka-admin/v1/events/1/mail' ) {
			return event instanceof Error ? Promise.reject( event ) : Promise.resolve( event );
		}
		if ( options.path.endsWith( '/mail/manual/preview' ) ) {
			return plan instanceof Error ? Promise.reject( plan ) : Promise.resolve( plan );
		}
		return send( options );
	} );
}

async function render( props = {} ) {
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
	closed = jest.fn();
	sent = jest.fn();
	await act( async () => {
		root.render(
			createElement( ManualMailForm, {
				event: 1,
				previewPath: '/kalaka-admin/v1/events/1/mail/manual/preview',
				selection: { codes: [ 'A1', 'A2', 'A3', 'A4' ] },
				onClose: closed,
				onSent: sent,
				...props,
			} )
		);
	} );
}

const select = () => host.querySelector( 'select' );
const submitButton = () => host.querySelector( 'button[type=submit]' );
const notices = ( status ) => Array.from( host.querySelectorAll( `[data-notice="${ status }"]` ) ).map( ( n ) => n.textContent );

async function choose( value ) {
	await act( async () => {
		const setter = Object.getOwnPropertyDescriptor( window.HTMLSelectElement.prototype, 'value' ).set;
		setter.call( select(), value );
		select().dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );
}

async function submit() {
	await act( async () => {
		host.querySelector( 'form' ).dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );
	} );
}

async function toggleLayout() {
	await act( async () => {
		host.querySelector( 'input[type=checkbox]' ).click();
	} );
}

beforeEach( () => {
	apiFetch.mockReset();
	route();
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

describe( 'the preview', () => {
	test( 'asks the server about exactly the ticked codes and shows who gets the mail and who is skipped, with the reason', async () => {
		await render();
		expect( calls( 'preview' ) ).toHaveLength( 1 );
		expect( calls( 'preview' )[ 0 ].data ).toEqual( { selection: { codes: [ 'A1', 'A2', 'A3', 'A4' ] } } );
		expect( host.textContent ).toContain( 'A levelet 2 jelentkező kapja meg.' );
		expect( host.textContent ).toContain( 'A3 – ' + MANUAL.reason_withdrawn );
		expect( host.textContent ).toContain( 'A4 – ' + MANUAL.reason_removed );
	} );

	test( 'the choice is the published bodies the read returned, and none is chosen for the user', async () => {
		await render();
		const options = Array.from( select().options ).map( ( o ) => o.textContent );
		expect( options ).toEqual( [ MANUAL.pick, 'Fontos közlemény', 'Tudnivalók' ] );
		expect( select().value ).toBe( '' );
	} );

	test( 'with no body chosen, Küldés sends nothing and says so', async () => {
		await render();
		await submit();
		expect( calls( 'send-manual-mail' ) ).toHaveLength( 0 );
		expect( host.textContent ).toContain( MANUAL.noBody );
	} );

	test( 'nobody to send to: Küldés is disabled', async () => {
		route( { plan: { ...PLAN, queue: [] } } );
		await render();
		expect( host.textContent ).toContain( MANUAL.toQueueNone );
		expect( submitButton().disabled ).toBe( true );
	} );

	test( 'codes that do not exist are listed (the server sends them to full access only)', async () => {
		route( { plan: { ...PLAN, unknown: [ 'ZZ9' ] } } );
		await render();
		expect( host.textContent ).toContain( MANUAL.unknown + ' ZZ9' );
	} );

	test( 'more codes than one send may hold: the server\'s 400 is told in the dialog\'s own sentence', async () => {
		route( { plan: Object.assign( new Error( 'Invalid parameter(s): codes' ), { code: 'rest_invalid_param', data: { status: 400 } } ) } );
		await render();
		expect( notices( 'error' ) ).toEqual( [ MANUAL.tooMany ] );
		expect( submitButton().disabled ).toBe( true );
	} );

	test( 'a refused preview (a code outside the grant\'s view) shows the server\'s text', async () => {
		route( { plan: Object.assign( new Error( 'Ehhez nincs hozzáférésed.' ), { code: 'kalaka_permission_denied', data: { status: 403 } } ) } );
		await render();
		expect( notices( 'error' ) ).toEqual( [ 'Ehhez nincs hozzáférésed.' ] );
	} );
} );

describe( 'the send', () => {
	test( 'is the ability with the body, the layout, the codes and a request key', async () => {
		await render();
		await choose( '20' );
		await submit();
		const [ call ] = calls( 'send-manual-mail' );
		expect( call.path ).toBe( '/wp-abilities/v1/abilities/kalaka/send-manual-mail/run' );
		expect( call.data.input ).toMatchObject( { event_id: 1, body_id: 20, selection: { codes: [ 'A1', 'A2', 'A3', 'A4' ] }, layout: true } );
		expect( call.data.input.request_key ).toMatch( /^[A-Za-z0-9_-]{8,64}$/ );
		expect( sent ).toHaveBeenCalledWith( SENT );
	} );

	test( 'a double click is one send: the second press while it runs is ignored', async () => {
		let release;
		route( { send: () => new Promise( ( resolve ) => ( release = resolve ) ) } );
		await render();
		await choose( '20' );
		await submit();
		await submit();
		expect( calls( 'send-manual-mail' ) ).toHaveLength( 1 );
		await act( async () => release( SENT ) );
		expect( calls( 'send-manual-mail' ) ).toHaveLength( 1 );
	} );

	test( 'a retry after a failure carries the same request key', async () => {
		let attempt = 0;
		route( { send: () => ( ++attempt === 1 ? Promise.reject( new TypeError( 'Failed to fetch' ) ) : Promise.resolve( SENT ) ) } );
		await render();
		await choose( '20' );
		await submit();
		expect( notices( 'error' ) ).toHaveLength( 1 );
		await submit();
		const keys = calls( 'send-manual-mail' ).map( ( c ) => c.data.input.request_key );
		expect( keys ).toHaveLength( 2 );
		expect( keys[ 1 ] ).toBe( keys[ 0 ] );
	} );

	test( 'a changed layout or body is another request: another key', async () => {
		route( { send: () => Promise.reject( new TypeError( 'Failed to fetch' ) ) } );
		await render();
		await choose( '20' );
		await submit();
		await toggleLayout();
		await submit();
		await choose( '22' );
		await submit();
		const keys = calls( 'send-manual-mail' ).map( ( c ) => c.data.input.request_key );
		expect( new Set( keys ).size ).toBe( 3 );
	} );

	test( 'a refusal keeps the dialog and shows the server\'s text as it is', async () => {
		const text = 'Előbb tedd közzé a levélsablont.';
		route( { send: () => Promise.reject( { code: 'kalaka_mail_body_unpublished', message: text, data: { status: 422 } } ) } );
		await render();
		await choose( '20' );
		await submit();
		expect( notices( 'error' ) ).toEqual( [ text ] );
		expect( closed ).not.toHaveBeenCalled();
		expect( submitButton() ).not.toBeNull();
	} );
} );

describe( 'the answer', () => {
	test( 'says how many were queued and who was skipped, and then only closes', async () => {
		await render();
		await choose( '20' );
		await submit();
		expect( host.textContent ).toContain( '2 levél a küldési sorba került.' );
		expect( host.textContent ).toContain( 'A3 – ' + MANUAL.reason_withdrawn );
		expect( host.querySelector( 'form' ) ).toBeNull();
		expect( notices( 'warning' ) ).toEqual( [] );
	} );

	test( 'tells that the mails wait while sending is paused and while the event is held', async () => {
		route( { send: () => Promise.resolve( { ...SENT, paused: true, held: true } ) } );
		await render();
		await choose( '20' );
		await submit();
		expect( notices( 'warning' ) ).toEqual( [ RESULT.paused, RESULT.held ] );
	} );

	test( 'tells a repeat: the first batch is answered and nothing new is queued', async () => {
		route( { send: () => Promise.resolve( { ...SENT, repeat: true } ) } );
		await render();
		await choose( '20' );
		await submit();
		expect( notices( 'info' ) ).toEqual( [ 'Ez a levél korábban már a küldési sorba került (2 levél), ezért újabb levél nem került a sorba.' ] );
		// The repeat sentence replaces the queued one: „2 levél a küldési sorba került.” is absent.
		expect( host.textContent ).not.toContain( '2 levél a küldési sorba került.' );
	} );
} );

describe( 'before the send', () => {
	test( 'a paused sender and a held event are told before anything is queued', async () => {
		route( { site: { paused: { cause: 'errors' } }, event: { held: true } } );
		await render();
		expect( notices( 'warning' ) ).toEqual( [ RESULT.paused, RESULT.held ] );
		expect( calls( 'send-manual-mail' ) ).toHaveLength( 0 );
	} );

	test( 'a running sender and a published event say nothing', async () => {
		await render();
		expect( notices( 'warning' ) ).toEqual( [] );
	} );

	test( 'a grant holder\'s reads are refused: nothing is said before the send, and the dialog still works', async () => {
		const refused = Object.assign( new Error( 'no' ), { code: 'kalaka_permission_denied' } );
		route( { site: refused, event: refused } );
		await render();
		expect( notices( 'warning' ) ).toEqual( [] );
		expect( select() ).not.toBeNull();
	} );
} );

describe( 'a double send across reopenings', () => {
	test( 'the same rows and body keep one request key when the dialog is closed and opened again', async () => {
		const keys = createKeys();
		await render( { keys } );
		await choose( '20' );
		await submit();
		await act( async () => root.unmount() );
		host.remove();
		await render( { keys } );
		await choose( '20' );
		await submit();
		const sent = calls( 'send-manual-mail' ).map( ( c ) => c.data.input.request_key );
		expect( sent ).toHaveLength( 2 );
		expect( sent[ 1 ] ).toBe( sent[ 0 ] );
	} );

	test( 'another selection is another request', async () => {
		const keys = createKeys();
		await render( { keys, selection: { codes: [ 'A1', 'A2' ] } } );
		await choose( '20' );
		await submit();
		await act( async () => root.unmount() );
		host.remove();
		await render( { keys, selection: { codes: [ 'A1', 'A3' ] } } );
		await choose( '20' );
		await submit();
		const sent = calls( 'send-manual-mail' ).map( ( c ) => c.data.input.request_key );
		expect( new Set( sent ).size ).toBe( 2 );
	} );
} );

describe( 'with nothing ticked: every row the filters and search leave', () => {
	const ALL = { view: 0, grant: 0 };
	const NARROWED = { view: 3, grant: 5, filters: [ { field: 'status', operator: 'isAny', value: [ 'registered' ] } ], search: 'kov' };

	test( 'the preview asks with the view, the grant, the filters and the search, and the dialog says how many rows', async () => {
		route( { plan: { ...PLAN, count: 612 } } );
		await render( { selection: NARROWED } );
		expect( calls( 'preview' )[ 0 ].data ).toEqual( { selection: NARROWED } );
		expect( host.textContent ).toContain( fmt( MANUAL.scopeNarrowed, 612 ) );
		expect( host.textContent ).toContain( 'A levelet 2 jelentkező kapja meg.' );
	} );

	test( 'a whole view with no filter says it is every registration', async () => {
		route( { plan: { ...PLAN, count: 612 } } );
		await render( { selection: ALL } );
		expect( host.textContent ).toContain( fmt( MANUAL.scopeAll, 612 ) );
	} );

	test( 'ticked codes carry no scope line', async () => {
		await render();
		expect( host.textContent ).not.toContain( 'Nincs kijelölve' );
	} );

	test( 'the send carries the same selection, with one request key per selection', async () => {
		const keys = createKeys();
		route( { plan: { ...PLAN, count: 3 } } );
		await render( { keys, selection: NARROWED } );
		await choose( '20' );
		await submit();
		await act( async () => root.unmount() );
		host.remove();
		await render( { keys, selection: { ...NARROWED } } );
		await choose( '20' );
		await submit();
		await act( async () => root.unmount() );
		host.remove();
		await render( { keys, selection: { ...NARROWED, search: 'nagy' } } );
		await choose( '20' );
		await submit();
		const sends = calls( 'send-manual-mail' );
		expect( sends[ 0 ].data.input.selection ).toEqual( NARROWED );
		expect( sends[ 0 ].data.input.codes ).toBeUndefined();
		expect( sends[ 1 ].data.input.request_key ).toBe( sends[ 0 ].data.input.request_key );
		expect( sends[ 2 ].data.input.request_key ).not.toBe( sends[ 0 ].data.input.request_key );
	} );
} );

describe( 'bodies with one subject', () => {
	test( 'are told apart by their number in the select', async () => {
		route( { plan: { ...PLAN, bodies: [ { id: 20, subject: 'Közlemény' }, { id: 22, subject: 'Közlemény' }, { id: 23, subject: 'Más' } ] } } );
		await render();
		expect( Array.from( select().options ).map( ( o ) => o.textContent ) ).toEqual( [
			MANUAL.pick,
			'Közlemény (20. számú levélsablon)',
			'Közlemény (22. számú levélsablon)',
			'Más',
		] );
	} );
} );
