/**
 * The grid's bulk action „Levél küldése”: DataViews gets an action only on a tab whose configuration says it carries
 * the manual mail, and the ticked rows are cleared when the tab, the page or the filters change.
 *
 * The real Page with small stand-ins, as `grid-page-sums.test.js`; the DataViews stand-in records its props.
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
	const DataViews = ( props ) => {
		globalThis.lastDataViews = props;
		return h( 'div', null, props.children );
	};
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
const tab = ( key, manualMail ) => ( { key, view: 'v', grant: 0, columns: [ column ], counts: {}, condition: null, manualMail } );
const config = {
	rest: '/kalaka/v1',
	perPages: [ 50 ],
	current: 'a',
	tabs: [ tab( 'a', true ), tab( 'b', false ) ],
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

test( 'a tab that carries the manual mail has the bulk action, and the selection is the grid\'s own state', async () => {
	await act( async () => root.render( createElement( Page, { config } ) ) );
	await flush();
	expect( globalThis.lastDataViews.actions ).toHaveLength( 1 );
	expect( globalThis.lastDataViews.selection ).toEqual( [] );
	await act( async () => globalThis.lastDataViews.onChangeSelection( [ 'K1' ] ) );
	expect( globalThis.lastDataViews.selection ).toEqual( [ 'K1' ] );
} );

test( 'a tab that does not carry it has no action at all', async () => {
	await act( async () => root.render( createElement( Page, { config: { ...config, current: 'b' } } ) ) );
	await flush();
	expect( globalThis.lastDataViews.actions ).toEqual( [] );
} );
