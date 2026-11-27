/**
 * The detail panel's tabs and the awaiting strip (panel-tabs ruling 2026-11-26): „Adatok”, „Kérelmek” and
 * „Pénzügyek” are separate tabs; the requests that await an answer always show above the tabs, with the answer
 * button, and nothing shows when none awaits. The real DetailPanel and RequestsBlock with small stand-ins for
 * wp-components and for the blocks that are not under test. Every string is unapproved until the owner ticks its
 * box: [UNAPPROVED T950] to [UNAPPROVED T952], see `PANEL`.
 */
import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import DetailPanel from '../../src/Admin/app/grid/DetailPanel';
import { awaitingOf, offeredOf } from '../../src/Admin/app/grid/RequestsBlock';
import { PANEL, REQUESTS } from '../../src/Admin/app/grid/texts';

jest.mock( '@wordpress/components', () => {
	const { createElement: h, useState } = require( 'react' );
	const Button = ( { children, label, onClick, ...rest } ) => h( 'button', { 'aria-label': label || rest[ 'aria-label' ], onClick }, children );
	const TabPanel = ( { tabs, initialTabName, children } ) => {
		const [ name, setName ] = useState( initialTabName );
		const tab = tabs.find( ( t ) => t.name === name ) || tabs[ 0 ];
		return h(
			'div',
			null,
			h(
				'div',
				{ role: 'tablist' },
				tabs.map( ( t ) =>
					h( 'button', { key: t.name, role: 'tab', 'aria-selected': t.name === tab.name, disabled: t.disabled, onClick: () => setName( t.name ) }, t.title )
				)
			),
			h( 'div', { 'data-tab': tab.name }, children( tab ) )
		);
	};
	return { Button, TabPanel, SnackbarList: () => null };
} );
jest.mock( '../../src/Admin/app/grid/api', () => ( { fetchDetail: jest.fn(), detailProblem: jest.fn() } ) );
jest.mock( '../../src/Admin/app/grid/AnswerDialog', () => ( { item, onDone } ) =>
	require( 'react' ).createElement( 'div', { 'data-dialog': item.id }, require( 'react' ).createElement( 'button', { 'data-done': true, onClick: () => onDone( '' ) }, 'done' ) )
);
jest.mock( '../../src/Admin/app/grid/StatusActs', () => () => null );
jest.mock( '../../src/Admin/app/grid/ManualConfirmation', () => () => null );
jest.mock( '../../src/Admin/app/grid/MoneyTab', () => () => require( 'react' ).createElement( 'p', null, 'money-body' ) );
jest.mock( '../../src/Admin/app/grid/FieldsBlock', () => () => require( 'react' ).createElement( 'p', null, 'fields-body' ) );

window.IS_REACT_ACT_ENVIRONMENT = true;

const aid = { id: 'aid', kind: 'aid', label: 'Részvételi támogatás', state: 'awaiting', amount: null };
const offer = { id: 'offer:church', kind: 'offer', label: 'Egyházi támogatás', state: 'approved', amount: null };
const field = { key: 'name', label: 'Név', text: 'A', value: 'A', writable: false };

const detailOf = ( over = {} ) => ( {
	registration: { name: 'X', code: 'AB12', status: 'registered', status_label: 'Jelentkezett' },
	can: { answer_request: true, view_money: true },
	money: { lines: [] },
	fields: [ field ],
	requests: { currency: 'HUF', items: [ aid, offer ] },
	...over,
} );

let host;
let root;
const mount = async ( detail ) => {
	require( '../../src/Admin/app/grid/api' ).fetchDetail.mockResolvedValue( detail );
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
	await act( async () => {
		root.render( createElement( DetailPanel, { config: {}, code: 'AB12', again: 0, onClose() {}, onSaved() {} } ) );
	} );
};
afterEach( () => {
	act( () => root.unmount() );
	host.remove();
} );
const tabTitles = () => Array.from( host.querySelectorAll( '[role="tab"]' ) ).map( ( t ) => t.textContent );
const strip = () => host.querySelector( '.kg-awaiting' );

describe( 'the panel tabs', () => {
	it( 'has Adatok, Kérelmek, Pénzügyek and the disabled last one, in that order, on the first as default', async () => {
		await mount( detailOf() );
		expect( tabTitles() ).toEqual( [ PANEL.tabFields, PANEL.tabRequests, PANEL.tabMoney, PANEL.tabSoon ] );
		expect( host.querySelector( '[data-tab]' ).dataset.tab ).toBe( 'fields' );
		expect( host.textContent ).toContain( 'fields-body' );
	} );

	it( 'switches to the requests and the money tab', async () => {
		await mount( detailOf() );
		const click = async ( title ) => {
			await act( async () => {
				Array.from( host.querySelectorAll( '[role="tab"]' ) ).find( ( t ) => t.textContent === title ).click();
			} );
		};
		await click( PANEL.tabRequests );
		expect( host.querySelector( '[data-tab]' ).dataset.tab ).toBe( 'requests' );
		expect( host.querySelectorAll( '.kg-requests li' ).length ).toBeGreaterThan( 1 );
		await click( PANEL.tabMoney );
		expect( host.textContent ).toContain( 'money-body' );
	} );

	it( 'leaves out the tabs the read has nothing for', async () => {
		await mount( detailOf( { can: { answer_request: false, view_money: false }, money: undefined } ) );
		expect( tabTitles() ).toEqual( [ PANEL.tabFields ] );
	} );

	it( 'has no tab list when nothing is on offer', async () => {
		await mount( detailOf( { can: {}, money: undefined, fields: [] } ) );
		expect( tabTitles() ).toEqual( [] );
	} );
} );

describe( 'the awaiting strip', () => {
	it( 'shows only the awaiting requests with the answer button, above the tabs and outside them', async () => {
		await mount( detailOf() );
		const bar = strip();
		expect( bar.querySelector( 'h3' ).textContent ).toBe( REQUESTS.awaiting );
		expect( Array.from( bar.querySelectorAll( 'li' ) ).map( ( li ) => li.textContent ) ).toEqual( [
			'Részvételi támogatás' + REQUESTS.answer,
		] );
		expect( bar.compareDocumentPosition( host.querySelector( '[role="tablist"]' ) ) & Node.DOCUMENT_POSITION_FOLLOWING ).toBeTruthy();
		expect( bar.closest( '[data-tab]' ) ).toBeNull();
	} );

	it( 'shows nothing when none awaits', async () => {
		await mount( detailOf( { requests: { currency: 'HUF', items: [ offer ] } } ) );
		expect( strip() ).toBeNull();
	} );

	it( 'shows nothing without the ability', async () => {
		await mount( detailOf( { can: { answer_request: false } } ) );
		expect( strip() ).toBeNull();
	} );

	it( 'opens the answer dialog from the strip', async () => {
		await mount( detailOf() );
		await act( async () => {
			strip().querySelector( 'button' ).click();
		} );
		expect( host.querySelector( '[data-dialog="aid"]' ) ).not.toBeNull();
	} );
} );

describe( 'the screen-reader names of the answer buttons (review m1)', () => {
	it( 'say which request each button answers, in the strip and on the tab', async () => {
		await mount( detailOf() );
		expect( strip().querySelector( 'button' ).getAttribute( 'aria-label' ) ).toBe( 'Válasz: Részvételi támogatás' );
		await act( async () => {
			Array.from( host.querySelectorAll( '[role="tab"]' ) ).find( ( t ) => t.textContent === PANEL.tabRequests ).click();
		} );
		const labels = Array.from( host.querySelectorAll( '[data-tab] li button' ) ).map( ( b ) => b.getAttribute( 'aria-label' ) );
		expect( labels ).toEqual( [ 'Válasz: Részvételi támogatás', 'Válasz módosítása: Egyházi támogatás' ] );
	} );
} );

describe( 'a failed re-read (review M1)', () => {
	it( 'keeps the tab list and the chosen tab, shows the failure text and no stale values or strip', async () => {
		const api = require( '../../src/Admin/app/grid/api' );
		await mount( detailOf() );
		await act( async () => {
			Array.from( host.querySelectorAll( '[role="tab"]' ) ).find( ( t ) => t.textContent === PANEL.tabRequests ).click();
		} );
		api.fetchDetail.mockRejectedValue( new Error( 'down' ) );
		api.detailProblem.mockReturnValue( 'failed' );
		await act( async () => {
			strip().querySelector( 'button' ).click();
		} );
		await act( async () => {
			host.querySelector( '[data-done]' ).click();
		} );
		expect( host.querySelector( '.kg-load-fail' ).textContent ).toBe( PANEL.loadFail );
		expect( tabTitles() ).toEqual( [ PANEL.tabFields, PANEL.tabRequests, PANEL.tabMoney, PANEL.tabSoon ] );
		expect( host.querySelector( '[role="tablist"]' ).style.display ).not.toBe( 'none' );
		expect( host.querySelector( '[data-tab]' ).dataset.tab ).toBe( 'requests' );
		expect( host.querySelectorAll( '[data-tab] li' ) ).toHaveLength( 0 );
		expect( strip() ).toBeNull();
	} );
} );

describe( 'what a panel offers', () => {
	it( 'offeredOf is empty without the ability or while stale; awaitingOf keeps the awaiting', () => {
		expect( offeredOf( detailOf(), false ) ).toHaveLength( 2 );
		expect( offeredOf( detailOf(), true ) ).toEqual( [] );
		expect( offeredOf( detailOf( { can: {} } ), false ) ).toEqual( [] );
		expect( awaitingOf( [ aid, offer ] ) ).toEqual( [ aid ] );
	} );
} );
