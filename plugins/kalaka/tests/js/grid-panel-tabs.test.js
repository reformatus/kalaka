/**
 * The detail panel's tabs (panel-tabs ruling 2026-11-26): „Adatok”, „Kérelmek” and „Pénzügyek” are separate
 * tabs, and „Kérelmek” carries a count badge of the requests that await an answer (owner 2026-11-27; no strip above
 * the tabs). The real DetailPanel and RequestsBlock with small stand-ins for wp-components and for the blocks that
 * are not under test. Every string is unapproved until the owner ticks its box: [UNAPPROVED T951] and
 * [UNAPPROVED T952], [UNAPPROVED T1309], see `PANEL`.
 */
import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import DetailPanel from '../../src/Admin/app/grid/DetailPanel';
import { awaitingCount, hasRequests, offeredOf } from '../../src/Admin/app/grid/RequestsBlock';
import { PANEL, REQUESTS } from '../../src/Admin/app/grid/texts';

jest.mock( '@wordpress/components', () => {
	const { createElement: h, useState } = require( 'react' );
	const Button = ( { children, label, onClick, ...rest } ) => h( 'button', { 'aria-label': label || rest[ 'aria-label' ], onClick }, children );
	const VisuallyHidden = ( { children } ) => h( 'span', { className: 'vh' }, children );
	const TabPanel = ( { tabs, initialTabName, onSelect, children } ) => {
		const [ name, setName ] = useState( initialTabName );
		const tab = tabs.find( ( t ) => t.name === name ) || tabs[ 0 ];
		return h(
			'div',
			null,
			h(
				'div',
				{ role: 'tablist' },
				tabs.map( ( t ) =>
					h( 'button', { key: t.name, 'data-name': t.name, role: 'tab', 'aria-selected': t.name === tab.name, disabled: t.disabled, onClick: () => {
							setName( t.name );
							if ( onSelect ) {
								onSelect( t.name );
							}
						} }, t.title )
				)
			),
			h( 'div', { 'data-tab': tab.name }, children( tab ) )
		);
	};
	return { Button, TabPanel, SnackbarList: () => null, VisuallyHidden };
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
beforeEach( () => {
	window.sessionStorage.clear();
} );
afterEach( () => {
	act( () => root.unmount() );
	host.remove();
} );
// What a screen reader announces for an element: its text without what is aria-hidden.
const spoken = ( node ) =>
	Array.from( node.childNodes )
		.map( ( child ) => {
			if ( child.nodeType === Node.TEXT_NODE ) {
				return child.textContent;
			}
			return child.getAttribute( 'aria-hidden' ) === 'true' ? '' : spoken( child );
		} )
		.join( '' );
const tabTitles = () => Array.from( host.querySelectorAll( '[role="tab"]' ) ).map( spoken );
const tab = ( name ) => host.querySelector( `[role="tab"][data-name="${ name }"]` );
const badge = () => host.querySelector( '.kg-tab-count' );
const awaitingTab = ( n ) => `${ PANEL.tabRequests }, ${ n } vár válaszra`;

describe( 'the panel tabs', () => {
	it( 'has Adatok, Kérelmek and Pénzügyek, in that order and no „Hamarosan…”, on the first as default', async () => {
		await mount( detailOf() );
		expect( tabTitles() ).toEqual( [ PANEL.tabFields, awaitingTab( 1 ), PANEL.tabMoney ] );
		expect( host.querySelector( '[data-tab]' ).dataset.tab ).toBe( 'fields' );
		expect( host.textContent ).toContain( 'fields-body' );
	} );

	it( 'switches to the requests and the money tab', async () => {
		await mount( detailOf() );
		const click = async ( title ) => {
			await act( async () => {
				tab( title ).click();
			} );
		};
		await click( 'requests' );
		expect( host.querySelector( '[data-tab]' ).dataset.tab ).toBe( 'requests' );
		expect( host.querySelectorAll( '.kg-requests li' ).length ).toBeGreaterThan( 1 );
		await click( 'money' );
		expect( host.textContent ).toContain( 'money-body' );
	} );

	it( 'leaves out only the tabs the read carries no part for (the viewer has no right to them)', async () => {
		await mount( detailOf( { can: { answer_request: false, view_money: false }, money: undefined, requests: undefined } ) );
		expect( tabTitles() ).toEqual( [ PANEL.tabFields ] );
	} );

	it( 'has no tab list when the read carries no part for any tab', async () => {
		await mount( detailOf( { can: {}, money: undefined, requests: undefined, fields: undefined } ) );
		expect( tabTitles() ).toEqual( [] );
	} );

	it( 'shows a tab whose part is present but empty, with its own empty text (N12, N1201)', async () => {
		await mount( detailOf( { fields: [], requests: { currency: 'HUF', items: [] } } ) );
		expect( tabTitles() ).toEqual( [ PANEL.tabFields, PANEL.tabRequests, PANEL.tabMoney ] );
		expect( badge() ).toBeNull();
		await act( async () => {
			tab( 'requests' ).click();
		} );
		expect( host.querySelector( '[data-tab] .kg-none' ).textContent ).toBe( REQUESTS.none );
	} );

	it( 'opens on the tab clicked last, also for another registration (N1201)', async () => {
		await mount( detailOf() );
		await act( async () => {
			tab( 'requests' ).click();
		} );
		await act( async () => root.unmount() );
		host.remove();
		await mount( detailOf() );
		expect( host.querySelector( '[data-tab]' ).dataset.tab ).toBe( 'requests' );
	} );

	it( 'falls back to the first tab when the remembered one is not there, and keeps the memory (N1264)', async () => {
		window.sessionStorage.setItem( 'kalaka-panel-tab', 'money' );
		await mount( detailOf( { can: { answer_request: true, view_money: false }, money: undefined } ) );
		expect( host.querySelector( '[data-tab]' ).dataset.tab ).toBe( 'fields' );
		expect( window.sessionStorage.getItem( 'kalaka-panel-tab' ) ).toBe( 'money' );
	} );
} );

describe( 'the „Kérelmek” count badge (T1309)', () => {
	it( 'shows the number of awaiting requests as the only visible text of the badge', async () => {
		const aid2 = { ...aid, id: 'aid2', label: 'Másik támogatás' };
		await mount( detailOf( { requests: { currency: 'HUF', items: [ aid, aid2, offer ] } } ) );
		expect( badge().textContent ).toBe( '2' );
		expect( tab( 'requests' ).contains( badge() ) ).toBe( true );
	} );

	it( 'is read as „Kérelmek, 2 vár válaszra”: the badge itself is hidden from a screen reader', async () => {
		const aid2 = { ...aid, id: 'aid2', label: 'Másik támogatás' };
		await mount( detailOf( { requests: { currency: 'HUF', items: [ aid, aid2, offer ] } } ) );
		expect( badge().getAttribute( 'aria-hidden' ) ).toBe( 'true' );
		expect( spoken( tab( 'requests' ) ) ).toBe( 'Kérelmek, 2 vár válaszra' );
	} );

	it( 'is not there when none awaits', async () => {
		await mount( detailOf( { requests: { currency: 'HUF', items: [ offer ] } } ) );
		expect( badge() ).toBeNull();
		expect( spoken( tab( 'requests' ) ) ).toBe( PANEL.tabRequests );
	} );

	it( 'does not count an inactive request', async () => {
		const inactive = { ...aid, id: 'in', state: 'inactive' };
		await mount( detailOf( { requests: { currency: 'HUF', items: [ inactive, offer ] } } ) );
		expect( badge() ).toBeNull();
	} );

	it( 'has no strip above the tabs; the answer button is on the tab only', async () => {
		await mount( detailOf() );
		expect( host.querySelector( '.kg-awaiting' ) ).toBeNull();
		expect( host.querySelector( '.kg-panel, aside' ).querySelectorAll( 'button[aria-label^="Válasz"]' ) ).toHaveLength( 0 );
		await act( async () => {
			tab( 'requests' ).click();
		} );
		await act( async () => {
			host.querySelector( '[data-tab] li button' ).click();
		} );
		expect( host.querySelector( '[data-dialog="aid"]' ) ).not.toBeNull();
	} );

	it( 'follows a re-read: the badge is gone once the request is answered', async () => {
		const api = require( '../../src/Admin/app/grid/api' );
		await mount( detailOf() );
		expect( badge().textContent ).toBe( '1' );
		await act( async () => {
			tab( 'requests' ).click();
		} );
		api.fetchDetail.mockResolvedValue( detailOf( { requests: { currency: 'HUF', items: [ { ...aid, state: 'approved' }, offer ] } } ) );
		await act( async () => {
			host.querySelector( '[data-tab] li button' ).click();
		} );
		await act( async () => {
			host.querySelector( '[data-done]' ).click();
		} );
		expect( badge() ).toBeNull();
	} );
} );

describe( 'the screen-reader names of the answer buttons (review m1)', () => {
	it( 'say which request each button answers', async () => {
		await mount( detailOf() );
		await act( async () => {
			tab( 'requests' ).click();
		} );
		const labels = Array.from( host.querySelectorAll( '[data-tab] li button' ) ).map( ( b ) => b.getAttribute( 'aria-label' ) );
		expect( labels ).toEqual( [ 'Válasz: Részvételi támogatás', 'Válasz módosítása: Egyházi támogatás' ] );
	} );
} );

describe( 'a failed re-read (review M1)', () => {
	it( 'keeps the tab list and the chosen tab, shows the failure text and no stale values or badge', async () => {
		const api = require( '../../src/Admin/app/grid/api' );
		await mount( detailOf() );
		await act( async () => {
			tab( 'requests' ).click();
		} );
		api.fetchDetail.mockRejectedValue( new Error( 'down' ) );
		api.detailProblem.mockReturnValue( 'failed' );
		await act( async () => {
			host.querySelector( '[data-tab] li button' ).click();
		} );
		await act( async () => {
			host.querySelector( '[data-done]' ).click();
		} );
		expect( host.querySelector( '.kg-load-fail' ).textContent ).toBe( PANEL.loadFail );
		expect( tabTitles() ).toEqual( [ PANEL.tabFields, PANEL.tabRequests, PANEL.tabMoney ] );
		expect( badge() ).toBeNull();
		expect( host.querySelector( '[role="tablist"]' ).style.display ).not.toBe( 'none' );
		expect( host.querySelector( '[data-tab]' ).dataset.tab ).toBe( 'requests' );
		expect( host.querySelectorAll( '[data-tab] li' ) ).toHaveLength( 0 );
	} );
} );

describe( 'what a panel offers', () => {
	it( 'offeredOf is what the read carries, empty without it or while stale; awaitingCount counts the awaiting', () => {
		expect( offeredOf( detailOf(), false ) ).toHaveLength( 2 );
		expect( offeredOf( detailOf(), true ) ).toEqual( [] );
		expect( offeredOf( detailOf( { requests: undefined } ), false ) ).toEqual( [] );
		expect( hasRequests( detailOf() ) ).toBe( true );
		expect( hasRequests( detailOf( { requests: undefined } ) ) ).toBe( false );
		expect( awaitingCount( [ aid, offer ] ) ).toBe( 1 );
		expect( awaitingCount( [ { ...aid, state: 'inactive' }, offer ] ) ).toBe( 0 );
		expect( awaitingCount( [] ) ).toBe( 0 );
	} );
} );
