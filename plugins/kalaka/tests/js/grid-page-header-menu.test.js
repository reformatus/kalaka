/**
 * One column menu: a click or key on a column's header opens the grid's own menu (DataViews' header menu is stopped
 * before it opens), anchored to the header; a filter is added once with the entry DataViews itself adds; focus
 * returns to the header when the menu closes.
 *
 * The real Page with small stand-ins, as `grid-page-sums.test.js`; the DataViews stand-in draws one header button
 * the way DataViews does (a `dataviews-view-table-header-button` that holds the column's marked header).
 */
import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import * as api from '../../src/Admin/app/grid/api';
import Page from '../../src/Admin/app/grid/Page';

jest.mock( '../../src/Admin/app/grid/api', () => ( {
	fetchPage: jest.fn(),
	fetchSums: jest.fn(),
	fetchAll: jest.fn(),
} ) );
jest.mock( '@wordpress/components', () => {
	const { createElement: h } = require( 'react' );
	return {
		Button: ( { children, label } ) => h( 'button', null, children || label ),
		SelectControl: () => null,
		SnackbarList: () => null,
		Tooltip: ( { children } ) => children,
	};
} );
jest.mock( '@wordpress/icons', () => ( { update: null } ) );
jest.mock( '@wordpress/dataviews/wp', () => {
	const { createElement: h } = require( 'react' );
	const DataViews = ( { children, fields, view, onChangeView } ) => {
		globalThis.dvView = view;
		globalThis.dvChange = onChangeView;
		return h(
			'div',
			null,
			h( 'button', { key: JSON.stringify( view.sort ), className: 'dataviews-view-table-header-button', id: 'native', onClick: () => globalThis.nativeOpened() }, fields[ 0 ].header ),
			children
		);
	};
	DataViews.Search = () => null;
	DataViews.FiltersToggle = () => null;
	DataViews.FiltersToggled = () => null;
	DataViews.Layout = () => null;
	DataViews.Pagination = () => null;
	return { DataViews };
} );
jest.mock( '../../src/Admin/app/mail/bulk', () => ( { manualMailAction: () => ( { id: 'mail' } ) } ) );
jest.mock( '../../src/Admin/app/grid/Tabs', () => ( { __esModule: true, default: () => null, tabUrl: () => '' } ) );
jest.mock( '../../src/Admin/app/grid/SumRow', () => ( {
	__esModule: true,
	default: ( { columns, menuFor } ) => menuFor( columns[ 0 ] ),
} ) );
jest.mock( '../../src/Admin/app/grid/filterReveal', () => ( { __esModule: true, default: jest.fn() } ) );
jest.mock( '../../src/Admin/app/grid/ColumnMenu', () => {
	const { createElement: h } = require( 'react' );
	return {
		__esModule: true,
		default: ( props ) => {
			globalThis.menuProps = props;
			return h(
				'button',
				{ id: 'menu', 'data-open': String( props.open ), onClick: () => props.onOpenChange( ! props.open ) },
				'menu'
			);
		},
	};
} );
jest.mock( '../../src/Admin/app/grid/DetailPanel', () => ( { __esModule: true, default: () => null } ) );

window.IS_REACT_ACT_ENVIRONMENT = true;
window.ResizeObserver = class {
	observe() {}
	disconnect() {}
};
window.requestAnimationFrame = ( fn ) => {
	fn();
	return 0;
};

const column = { column: 'state', label: 'Állapot', type: 'text', sortable: true, numeric: false };
const config = {
	rest: '/kalaka/v1',
	perPages: [ 50 ],
	current: 'a',
	tabs: [ { key: 'a', view: 'v', grant: 0, columns: [ column ], counts: {}, condition: null } ],
};

let root;
let host;
const flush = () => act( async () => {} );
const header = () => host.querySelector( '#native' );

beforeEach( async () => {
	globalThis.nativeOpened = jest.fn();
	api.fetchPage.mockReset().mockResolvedValue( { rows: [], total: 0 } );
	api.fetchSums.mockReset().mockResolvedValue( { state: { members: [] } } );
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
	await act( async () => root.render( createElement( Page, { config } ) ) );
	await flush();
} );
afterEach( () => {
	act( () => root.unmount() );
	host.remove();
} );

test( 'a click on the header opens the grid\'s menu for that column and never DataViews\' own', async () => {
	expect( globalThis.menuProps.open ).toBe( false );
	await act( async () => header().click() );
	expect( globalThis.nativeOpened ).not.toHaveBeenCalled();
	expect( globalThis.menuProps.open ).toBe( true );
	expect( globalThis.menuProps.anchor ).toBe( header() );
} );

test( 'the arrow opens the same menu, anchored to itself, and closing it clears the anchor', async () => {
	await act( async () => host.querySelector( '#menu' ).click() );
	expect( globalThis.menuProps.open ).toBe( true );
	expect( globalThis.menuProps.anchor ).toBeUndefined();
	await act( async () => host.querySelector( '#menu' ).click() );
	expect( globalThis.menuProps.open ).toBe( false );
} );

test( 'Arrow Down on the header opens the menu; Enter is left to the click the browser then makes', async () => {
	const press = ( key ) => {
		const event = new window.KeyboardEvent( 'keydown', { key, bubbles: true, cancelable: true } );
		header().dispatchEvent( event );
		return event;
	};
	let event;
	await act( async () => {
		event = press( 'ArrowDown' );
	} );
	expect( event.defaultPrevented ).toBe( true );
	expect( globalThis.menuProps.open ).toBe( true );
	await act( async () => host.querySelector( '#menu' ).click() );
	await act( async () => {
		event = press( 'Enter' );
	} );
	expect( event.defaultPrevented ).toBe( false );
	expect( globalThis.menuProps.open ).toBe( false );
} );

test( 'focus goes back to the header button when a menu opened from it closes', async () => {
	await act( async () => header().click() );
	const focus = jest.spyOn( header(), 'focus' );
	await act( async () => host.querySelector( '#menu' ).click() );
	expect( focus ).toHaveBeenCalled();
} );

test( 'a filter is added once, as the entry DataViews adds: the column, its first operator and no value', async () => {
	expect( globalThis.menuProps.filterable ).toBe( true );
	expect( globalThis.menuProps.filtered ).toBe( false );
	await act( async () => globalThis.menuProps.onAddFilter( column ) );
	await flush();
	expect( globalThis.dvView.filters ).toEqual( [ { field: 'state', operator: 'contains', value: undefined } ] );
	expect( globalThis.dvView.page ).toBe( 1 );
	expect( globalThis.menuProps.filtered ).toBe( true );
} );

test( 'after a sort chosen in the menu the grid redraws its header, and focus still ends on the new header button', async () => {
	await act( async () => header().click() );
	const before = header();
	await act( async () => globalThis.menuProps.onSort( { field: 'state', direction: 'desc' } ) );
	expect( header() ).not.toBe( before );
	await act( async () => host.querySelector( '#menu' ).click() );
	await flush();
	expect( document.activeElement ).toBe( header() );
} );
