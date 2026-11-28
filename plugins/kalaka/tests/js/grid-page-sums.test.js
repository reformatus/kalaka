/**
 * The „Jelentkezők” page keeps the viewer's counting picks (session only, S37) when it quietly re-reads after
 * the detail panel saved an entry (S38): the refreshed sums are asked with the same picks as the sums effect,
 * and an answer for older picks is dropped.
 *
 * The test renders the real Page with small stand-ins for DataViews, wp-components and the panel; `api.js` is a
 * recording stub.
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
	const Button = ( { children, label } ) => h( 'button', null, children || label );
	return {
		Button,
		SelectControl: () => null,
		SnackbarList: () => null,
		Tooltip: ( { children } ) => children,
	};
} );
jest.mock( '@wordpress/icons', () => ( { update: null } ) );
jest.mock( '@wordpress/dataviews/wp', () => {
	const { createElement: h } = require( 'react' );
	const DataViews = ( { children, data, fields } ) =>
		h( 'div', null, data.map( ( item ) => fields.map( ( f ) => h( 'div', { key: item.key + f.id }, f.render( { item } ) ) ) ), children );
	DataViews.Search = () => null;
	DataViews.FiltersToggle = () => null;
	DataViews.FiltersToggled = () => null;
	DataViews.Layout = () => null;
	DataViews.Pagination = () => null;
	DataViews.BulkActionToolbar = () => null;
	return { DataViews };
} );
// The bulk action's dialog is the mail module's (its own test); here it is only offered.
jest.mock( '../../src/Admin/app/mail/bulk', () => ( { manualMailAction: () => ( { id: 'mail' } ) } ) );
jest.mock( '../../src/Admin/app/grid/Tabs', () => ( { __esModule: true, default: () => null, tabUrl: () => '' } ) );
// The sum row shows the column menu in place; the menu is one button that ticks the member „x”.
jest.mock( '../../src/Admin/app/grid/SumRow', () => ( {
	__esModule: true,
	default: ( { columns, menuFor } ) => menuFor( columns[ 0 ] ),
} ) );
jest.mock( '../../src/Admin/app/grid/ColumnMenu', () => {
	const { createElement: h } = require( 'react' );
	return {
		__esModule: true,
		default: ( { column, chosen, onToggle } ) =>
			h(
				'button',
				{ id: 'pick', 'data-chosen': JSON.stringify( chosen ), onClick: () => onToggle( column, 'x', true ) },
				'pick'
			),
	};
} );
jest.mock( '../../src/Admin/app/grid/DetailPanel', () => {
	const { createElement: h } = require( 'react' );
	return { __esModule: true, default: ( { onSaved } ) => h( 'button', { id: 'saved', onClick: onSaved }, 'saved' ) };
} );

window.IS_REACT_ACT_ENVIRONMENT = true;
window.ResizeObserver = class {
	observe() {}
	disconnect() {}
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

beforeEach( () => {
	api.fetchPage.mockReset().mockResolvedValue( { rows: [ { key: 'K1', values: { state: 'a' } } ], total: 1 } );
	api.fetchSums.mockReset().mockResolvedValue( { state: { members: [] } } );
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
} );

afterEach( () => {
	act( () => root.unmount() );
	host.remove();
} );

test( 'a save refresh asks the sums with the viewer\'s counting picks, like the sums effect', async () => {
	await act( async () => root.render( createElement( Page, { config } ) ) );
	await flush();
	expect( api.fetchSums.mock.calls[ 0 ][ 3 ] ).toBeNull();

	await act( async () => host.querySelector( '#pick' ).click() );
	await flush();
	const picked = api.fetchSums.mock.calls.at( -1 );
	expect( picked[ 3 ] ).toEqual( { state: [ 'x' ] } );

	// The panel (opened from the row) saved an entry: the grid re-reads its sums, and keeps the pick.
	api.fetchSums.mockClear();
	await act( async () => host.querySelector( '.kg-open' ).click() );
	await flush();
	await act( async () => host.querySelector( '#saved' ).click() );
	await flush();
	expect( api.fetchSums ).toHaveBeenCalledTimes( 1 );
	expect( api.fetchSums.mock.calls[ 0 ][ 3 ] ).toEqual( { state: [ 'x' ] } );
} );
