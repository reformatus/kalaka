import { createElement } from '@wordpress/element';
import { createRoot } from 'react-dom/client';
import { act } from 'react';
import Page from '../../src/Admin/app/grid/Page';

// The WordPress packages are ESM-only for Jest here: stand-ins that render what the page hands them. DataViews
// shows its `view` and lets a test call its `onChangeView`, as a typed search or an added filter would.
const mockDataViews = { view: null, onChangeView: null };
const mockColumnMenu = { props: null };

jest.mock( '@wordpress/components', () => {
	const { createElement: h } = require( '@wordpress/element' );
	return {
		Button: ( { children, onClick, variant } ) => h( 'button', { 'data-variant': variant, onClick }, children ),
		SelectControl: () => null,
		SnackbarList: () => null,
		Tooltip: ( { children } ) => children,
	};
} );
jest.mock( '@wordpress/dataviews/wp', () => {
	const { createElement: h } = require( '@wordpress/element' );
	const DataViews = ( { view, onChangeView, children } ) => {
		mockDataViews.view = view;
		mockDataViews.onChangeView = onChangeView;
		return h( 'div', null, children );
	};
	DataViews.Search = () => null;
	DataViews.FiltersToggle = () => null;
	DataViews.FiltersToggled = () => null;
	DataViews.Layout = () => null;
	DataViews.Pagination = () => null;
	return { DataViews };
} );
jest.mock( '../../src/Admin/app/grid/Tabs', () => ( { __esModule: true, default: () => null, tabUrl: () => '#' } ) );
jest.mock( '../../src/Admin/app/grid/Cell', () => ( { __esModule: true, default: () => null } ) );
jest.mock( '../../src/Admin/app/grid/ColumnMenu', () => ( {
	__esModule: true,
	default: ( props ) => {
		mockColumnMenu.props = props;
		return null;
	},
} ) );
jest.mock( '../../src/Admin/app/grid/SumRow', () => {
	const { createElement: h } = require( '@wordpress/element' );
	return { __esModule: true, default: ( { columns, menuFor } ) => h( 'div', null, menuFor( columns.find( ( c ) => c.column === 'status' ) ) ) };
} );
jest.mock( '../../src/Admin/app/grid/api', () => ( {
	fetchPage: jest.fn( () => Promise.resolve( { rows: [], total: 0 } ) ),
	fetchSums: jest.fn( () => Promise.resolve( {} ) ),
	fetchAll: jest.fn( () => Promise.resolve( [] ) ),
} ) );

globalThis.IS_REACT_ACT_ENVIRONMENT = true;
window.ResizeObserver = class {
	observe() {}
	disconnect() {}
};

const COLUMNS = [
	{ column: 'code', label: 'Kód', type: 'code', sortable: true },
	{ column: 'status', label: 'Állapot', type: 'status', sortable: true, options: { registered: 'Regisztrált', waitlisted: 'Várólistás' } },
	{ column: 'name', label: 'Név', type: 'text', sortable: true },
];

const config = ( tab ) => ( {
	current: 'one',
	perPages: [ 50, 100 ],
	maxLimit: 100,
	rest: 'kalaka-admin/v1',
	tabs: [ { key: 'one', view: 1, grant: 0, columns: COLUMNS, counts: { status: [ 'registered' ] }, condition: null, ...tab } ],
} );

async function mount( tab ) {
	const host = document.createElement( 'div' );
	document.body.appendChild( host );
	const root = createRoot( host );
	await act( async () => root.render( createElement( Page, { config: config( tab ) } ) ) );
	const api = {
		host,
		view: () => mockDataViews.view,
		resetButton: () => [ ...host.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent === 'Visszaállítás' ),
		change: async ( patch ) => act( async () => mockDataViews.onChangeView( { ...mockDataViews.view, ...patch } ) ),
		pick: async ( member ) => act( async () => mockColumnMenu.props.onToggle( COLUMNS[ 1 ], member, true ) ),
		click: async ( button ) => act( async () => button.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true } ) ) ),
		done: () => {
			act( () => root.unmount() );
			host.remove();
		},
	};
	return api;
}

describe( 'the one „Visszaállítás” of the grid page (N7, T38)', () => {
	test( 'a fresh view shows no reset, and DataViews’ own is not the only one: ours has the visible label', async () => {
		const p = await mount();
		expect( p.view().sort ).toEqual( { field: 'code', direction: 'asc' } );
		expect( p.resetButton() ).toBeUndefined();
		p.done();
	} );

	test( 'a search shows the reset, and the reset clears the search and returns to page 1', async () => {
		const p = await mount();
		await p.change( { search: 'Anna', page: 3 } );
		expect( p.resetButton() ).toBeDefined();
		await p.click( p.resetButton() );
		expect( p.view().search ).toBe( '' );
		expect( p.view().page ).toBe( 1 );
		expect( p.resetButton() ).toBeUndefined();
		p.done();
	} );

	test( 'an added filter shows the reset, and the reset removes it', async () => {
		const p = await mount();
		await p.change( { filters: [ { field: 'status', operator: 'isAny', value: [ 'waitlisted' ] } ] } );
		expect( p.resetButton() ).toBeDefined();
		await p.click( p.resetButton() );
		expect( p.view().filters ).toEqual( [] );
		expect( p.resetButton() ).toBeUndefined();
		p.done();
	} );

	test( 'a filter just added with no value yet is also something to reset', async () => {
		const p = await mount();
		await p.change( { filters: [ { field: 'name', operator: 'contains', value: undefined } ] } );
		expect( p.resetButton() ).toBeDefined();
		await p.click( p.resetButton() );
		expect( p.view().filters ).toEqual( [] );
		p.done();
	} );

	test( 'another sort shows the reset, and the reset returns to Kód ascending', async () => {
		const p = await mount();
		await p.change( { sort: { field: 'name', direction: 'desc' } } );
		expect( p.resetButton() ).toBeDefined();
		await p.click( p.resetButton() );
		expect( p.view().sort ).toEqual( { field: 'code', direction: 'asc' } );
		expect( p.resetButton() ).toBeUndefined();
		p.done();
	} );

	test( 'a view without Kód has no default sort, and the reset clears the sort', async () => {
		const p = await mount( { columns: COLUMNS.slice( 1 ) } );
		expect( p.view().sort ).toBeUndefined();
		await p.change( { sort: { field: 'name', direction: 'asc' } } );
		await p.click( p.resetButton() );
		expect( p.view().sort ).toBeUndefined();
		p.done();
	} );

	test( 'the viewer’s own counting shows the reset, and the reset returns the stored counting', async () => {
		const p = await mount();
		expect( mockColumnMenu.props.chosen ).toEqual( [ 'registered' ] );
		await p.pick( 'waitlisted' );
		expect( mockColumnMenu.props.chosen ).toEqual( [ 'registered', 'waitlisted' ] );
		expect( p.resetButton() ).toBeDefined();
		await p.click( p.resetButton() );
		expect( mockColumnMenu.props.chosen ).toEqual( [ 'registered' ] );
		expect( p.resetButton() ).toBeUndefined();
		p.done();
	} );

	test( 'one click resets all four together and keeps the view’s own locked condition and the page size', async () => {
		const p = await mount( { condition: { text: 'Csak az ebédet kérők' } } );
		const locked = p.view().filters;
		expect( locked ).toHaveLength( 1 );
		expect( locked[ 0 ].isLocked ).toBe( true );
		// The locked condition alone is the fresh state: nothing to reset.
		expect( p.resetButton() ).toBeUndefined();
		await p.change( {
			search: 'Anna',
			filters: [ ...locked, { field: 'name', operator: 'contains', value: 'a' } ],
			sort: { field: 'name', direction: 'desc' },
			perPage: 100,
			page: 2,
		} );
		await p.pick( 'waitlisted' );
		await p.click( p.resetButton() );
		const v = p.view();
		expect( v.search ).toBe( '' );
		expect( v.filters ).toEqual( locked );
		expect( v.sort ).toEqual( { field: 'code', direction: 'asc' } );
		expect( v.page ).toBe( 1 );
		expect( v.perPage ).toBe( 100 );
		expect( mockColumnMenu.props.chosen ).toEqual( [ 'registered' ] );
		expect( p.resetButton() ).toBeUndefined();
		p.done();
	} );

	test( 'the reset is a text button with the label, not an icon button', async () => {
		const p = await mount();
		await p.change( { search: 'Anna' } );
		const button = p.resetButton();
		expect( button.textContent ).toBe( 'Visszaállítás' );
		expect( button.querySelector( 'svg' ) ).toBeNull();
		p.done();
	} );
} );
