/**
 * The event page's „Levelek” tab (each card's controls are a group named by the mail, a body is chosen and then saved
 * by its own button, a switch the read does not allow stays reachable and described, a typed layout survives a change
 * of tab), the failed list's follow-up (failed rows only) and the pure helpers (the body's label, the state reads).
 *
 * The real components with small stand-ins for wp-components.
 */
import { act, createElement, useState } from 'react';
import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';
import ActionMails from '../../src/Admin/app/mail/ActionMails';
import useDrafts, { hasUnsaved } from '../../src/Admin/app/mail/drafts';
import Troubled from '../../src/Admin/app/mail/Troubled';
import { bodyLabel } from '../../src/Admin/app/mail/format';
import { stateReads } from '../../src/Admin/app/mail/manual';

jest.mock( '@wordpress/components', () => {
	const { createElement: h } = require( 'react' );
	const Button = ( { children, onClick, disabled, ...rest } ) =>
		h( 'button', { onClick, disabled, 'aria-label': rest[ 'aria-label' ] }, children );
	const Card = ( { children } ) => h( 'section', null, children );
	const CardHeader = ( { children } ) => h( 'header', null, children );
	const CardBody = ( { children, ...rest } ) => h( 'div', rest, children );
	const Notice = ( { children } ) => h( 'div', null, children );
	const ToggleControl = ( { label, checked, disabled, help, onChange, ...rest } ) =>
		h(
			'label',
			null,
			label,
			h( 'input', {
				type: 'checkbox',
				checked,
				disabled,
				'aria-disabled': rest[ 'aria-disabled' ],
				onChange: ( e ) => onChange( e.target.checked ),
			} ),
			help && h( 'span', { 'data-help': true }, help )
		);
	const SelectControl = ( { label, value, options, onChange } ) =>
		h(
			'label',
			null,
			label,
			h(
				'select',
				{ value, onChange: ( e ) => onChange( e.target.value ) },
				options.map( ( o ) => h( 'option', { key: o.value, value: o.value }, o.label ) )
			)
		);
	const TextareaControl = ( { label, value, onChange } ) =>
		h( 'label', null, label, h( 'textarea', { value, onChange: ( e ) => onChange( e.target.value ) } ) );
	return { Button, Card, CardBody, CardHeader, Notice, SelectControl, TextareaControl, ToggleControl };
} );
jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: jest.fn() } ), { virtual: true } );
jest.mock( '../../src/Admin/app/mail/ListTable', () => {
	const { createElement: h } = require( 'react' );
	return {
		__esModule: true,
		default: ( { data, fields } ) =>
			h(
				'div',
				null,
				data.map( ( item ) => h( 'div', { key: item.id, 'data-row': item.code }, fields.map( ( f ) => h( 'span', { key: f.id }, f.render ? f.render( { item } ) : null ) ) ) )
			),
	};
} );
jest.mock( '../../src/Admin/app/mail/ManualMailDialog', () => ( { __esModule: true, default: () => null } ) );

window.IS_REACT_ACT_ENVIRONMENT = true;

const mail = ( over ) => ( {
	mail: 'registered',
	label: 'Jelentkezés rögzítve',
	enabled: false,
	body_id: null,
	layout: true,
	can: { switch_on: true, switch_off: true },
	refusals: {},
	...over,
} );
const BODIES = [
	{ id: 20, subject: 'Közlemény', status: 'publish' },
	{ id: 22, subject: 'Közlemény', status: 'draft' },
];
const DATA = ( mails ) => ( { held: false, mails, bodies: BODIES, layout_html: '{{content}}' } );

let host;
let root;
let saves;

function Harness( { data, onChanged = () => Promise.resolve() } ) {
	const drafts = useDrafts();
	const [ shown, setShown ] = useState( true );
	return createElement(
		'div',
		null,
		createElement( 'button', { id: 'toggle', onClick: () => setShown( ( s ) => ! s ) }, 'tab' ),
		shown && createElement( ActionMails, { config: { event: 1 }, data, onChanged, say: () => {}, drafts } )
	);
}

async function mount( element ) {
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
	await act( async () => root.render( element ) );
}

const setValue = async ( element, value, proto, event = 'change' ) =>
	act( async () => {
		Object.getOwnPropertyDescriptor( proto, 'value' ).set.call( element, value );
		element.dispatchEvent( new Event( event, { bubbles: true } ) );
	} );

beforeEach( () => {
	saves = [];
	apiFetch.mockReset();
	apiFetch.mockImplementation( ( options ) => {
		saves.push( options );
		return Promise.resolve( {} );
	} );
} );

afterEach( async () => {
	if ( root ) {
		await act( async () => root.unmount() );
		root = null;
	}
	if ( host ) {
		host.remove();
		host = null;
	}
} );

describe( 'a mail card', () => {
	test( 'its controls are one group named by the mail', async () => {
		await mount( createElement( Harness, { data: DATA( [ mail( {} ), mail( { mail: 'waitlisted', label: 'Jelentkezés várólistára helyezve' } ) ] ) } ) );
		const groups = Array.from( host.querySelectorAll( '[role="group"]' ) );
		expect( groups ).toHaveLength( 2 );
		const names = groups.map( ( g ) => document.getElementById( g.getAttribute( 'aria-labelledby' ) ).textContent );
		expect( names ).toEqual( [ 'Jelentkezés rögzítve', 'Jelentkezés várólistára helyezve' ] );
	} );

	test( 'choosing a body saves nothing until its own Mentés is pressed', async () => {
		await mount( createElement( Harness, { data: DATA( [ mail( {} ) ] ) } ) );
		const card = () => host.querySelector( '[role="group"]' );
		const saveIn = () => Array.from( card().querySelectorAll( 'button' ) ).find( ( b ) => b.textContent === 'Mentés' );
		expect( saveIn() ).toBeUndefined();
		expect( host.textContent ).not.toContain( 'Nincs mentve' );
		await setValue( host.querySelector( 'select' ), '20', window.HTMLSelectElement.prototype );
		await setValue( host.querySelector( 'select' ), '22', window.HTMLSelectElement.prototype );
		expect( saves ).toHaveLength( 0 );
		// The unsaved choice says so beside the button.
		expect( card().textContent ).toContain( 'Nincs mentve' );
		await act( async () => saveIn().click() );
		expect( saves ).toHaveLength( 1 );
		expect( saves[ 0 ].data.input ).toMatchObject( { event_id: 1, mail: 'registered', body_id: 22 } );
	} );

	test( 'two bodies with one subject are told apart by their number', async () => {
		await mount( createElement( Harness, { data: DATA( [ mail( {} ) ] ) } ) );
		expect( Array.from( host.querySelectorAll( 'option' ) ).map( ( o ) => o.textContent ) ).toEqual( [
			'– nincs –',
			'Közlemény (20. számú levélsablon)',
			'Közlemény (vázlat, 22. számú levélsablon)',
		] );
	} );

	test( 'a refused switch stays reachable and carries its refusal as text; it saves nothing', async () => {
		const refusal = { message: 'A művelet nem hajtható végre. Előbb add meg a sablon szövegét.' };
		await mount(
			createElement( Harness, { data: DATA( [ mail( { can: { switch_on: false, switch_off: false }, refusals: { switch_on: refusal } } ) ] ) } )
		);
		const toggle = host.querySelector( 'input[type=checkbox]' );
		expect( toggle.disabled ).toBe( false );
		expect( toggle.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		expect( host.querySelector( '[data-help]' ).textContent ).toBe( refusal.message );
		await act( async () => toggle.click() );
		expect( saves ).toHaveLength( 0 );
	} );

	test( 'an allowed switch saves at once', async () => {
		await mount( createElement( Harness, { data: DATA( [ mail( {} ) ] ) } ) );
		await act( async () => host.querySelector( 'input[type=checkbox]' ).click() );
		expect( saves[ 0 ].data.input ).toMatchObject( { enabled: true } );
	} );
} );

test( 'a typed layout and a chosen body survive a change of tab', async () => {
	await mount( createElement( Harness, { data: DATA( [ mail( {} ) ] ) } ) );
	await setValue( host.querySelector( 'textarea' ), '<p>{{content}}</p>', window.HTMLTextAreaElement.prototype );
	await setValue( host.querySelector( 'select' ), '20', window.HTMLSelectElement.prototype );
	await act( async () => host.querySelector( '#toggle' ).click() );
	expect( host.querySelector( 'textarea' ) ).toBeNull();
	await act( async () => host.querySelector( '#toggle' ).click() );
	expect( host.querySelector( 'textarea' ).value ).toBe( '<p>{{content}}</p>' );
	expect( host.querySelector( 'select' ).value ).toBe( '20' );
} );

describe( 'the failed list', () => {
	const rows = [
		{ id: 1, code: 'F1', status: 'failed', label: 'Jelentkezés rögzítve', attempts: 3, queued_at: '2026-11-25T10:00:00Z', last_attempt_at: null, error: 'x', batch_id: null },
		{ id: 2, code: 'P2', status: 'pending', label: 'Jelentkezés rögzítve', attempts: 1, queued_at: '2026-11-25T10:00:00Z', last_attempt_at: null, error: 'x', batch_id: null },
		{ id: 3, code: 'S3', status: 'sending', label: 'Jelentkezés rögzítve', attempts: 1, queued_at: '2026-11-25T10:00:00Z', last_attempt_at: null, error: null, batch_id: null },
	];

	test( 'only a failed delivery offers the follow-up', async () => {
		apiFetch.mockResolvedValue( rows );
		await mount( createElement( Troubled, { config: { rest: '/kalaka-admin/v1', event: 1, timezone: 'UTC' } } ) );
		await act( async () => {} );
		const buttons = ( code ) => host.querySelectorAll( `[data-row="${ code }"] button` ).length;
		expect( buttons( 'F1' ) ).toBe( 1 );
		expect( buttons( 'P2' ) ).toBe( 0 );
		expect( buttons( 'S3' ) ).toBe( 0 );
	} );
} );

describe( 'unsaved entries', () => {
	const data = DATA( [ mail( { body_id: 20 } ) ] );

	test( 'a chosen body or a typed layout that differs from the saved state is unsaved', () => {
		expect( hasUnsaved( {}, data ) ).toBe( false );
		expect( hasUnsaved( { 'body:registered': '22' }, data ) ).toBe( true );
		expect( hasUnsaved( { layout: '<b>{{content}}</b>' }, data ) ).toBe( true );
	} );

	test( 'an entry equal to the saved state, or no read yet, is not', () => {
		expect( hasUnsaved( { 'body:registered': '20', layout: '{{content}}' }, data ) ).toBe( false );
		expect( hasUnsaved( { layout: 'x' }, null ) ).toBe( false );
	} );
} );

describe( 'helpers', () => {
	test( 'bodyLabel: a unique subject stays as it is, a draft gets its status in lowercase', () => {
		const all = [ { id: 1, subject: 'A', status: 'publish' }, { id: 2, subject: 'B', status: 'draft' } ];
		expect( bodyLabel( all[ 0 ], all ) ).toBe( 'A' );
		expect( bodyLabel( all[ 1 ], all ) ).toBe( 'B (vázlat)' );
		expect( bodyLabel( { id: 3, subject: 'C' }, [ { id: 3, subject: 'C' } ] ) ).toBe( 'C' );
	} );

	test( 'stateReads: the sender read and the event read, from the preview path', () => {
		expect( stateReads( '/kalaka-admin/v1/events/7/mail/manual/preview' ) ).toEqual( {
			event: '/kalaka-admin/v1/events/7/mail',
			site: '/kalaka-admin/v1/mail',
		} );
	} );
} );
