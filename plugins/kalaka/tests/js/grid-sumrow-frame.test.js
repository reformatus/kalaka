/**
 * The sum row stays under its own columns when the table carries row actions: DataViews then puts a selection column
 * before the fields and an actions column after them, and the sum row gets an empty cell at each end. Without actions
 * it has exactly one cell per field. The row checkboxes are named with the registration's code.
 *
 * The real SumRow in a real table, as DataViews builds its head.
 */
import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import SumRow from '../../src/Admin/app/grid/SumRow';
import { labelRowCheckboxes } from '../../src/Admin/app/grid/selectionLabels';

window.IS_REACT_ACT_ENVIRONMENT = true;

const columns = [
	{ column: 'code', label: 'Kód', numeric: false },
	{ column: 'name', label: 'Név', numeric: false },
	{ column: 'nights', label: 'Éjszakák', numeric: true },
];
const SUMS = { code: { members: [], count: 3 }, name: { members: [] }, nights: { members: [] } };

let host;
let root;

function table( withActions ) {
	host = document.createElement( 'div' );
	const fields = columns.map( ( c ) => `<th data-field="${ c.column }">${ c.label }</th>` ).join( '' );
	host.innerHTML =
		'<table><thead><tr>' +
		( withActions ? '<td class="dataviews-view-table__checkbox-column"></td>' : '' ) +
		fields +
		( withActions ? '<th class="dataviews-view-table__actions-column"></th>' : '' ) +
		'</tr></thead><tbody>' +
		[ 'K1', 'K2' ]
			.map(
				( key ) =>
					'<tr>' +
					( withActions ? '<td class="dataviews-view-table__checkbox-column"><input type="checkbox" aria-label="(no title)"></td>' : '' ) +
					`<td>${ key }</td><td></td><td></td>` +
					( withActions ? '<td></td>' : '' ) +
					'</tr>'
			)
			.join( '' ) +
		'</tbody></table>';
	document.body.appendChild( host );
	return host;
}

async function mount( framed, selected = 0 ) {
	const container = table( framed );
	root = createRoot( document.createElement( 'div' ) );
	await act( async () => {
		root.render(
			createElement( SumRow, {
				root: container,
				columns,
				order: [ 'code', 'name', 'nights' ],
				sums: SUMS,
				menuFor: ( column ) => createElement( 'button', { 'data-menu': column.column }, '▾' ),
				narrowed: false,
				framed,
				selected,
			} )
		);
	} );
	return container;
}

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

test( 'with row actions every sum cell sits at the index of its own header cell', async () => {
	const container = await mount( true );
	const head = container.querySelector( 'thead tr:first-child' ).children;
	const sums = container.querySelector( 'tr.kg-sumtr' ).children;
	expect( sums ).toHaveLength( head.length );
	columns.forEach( ( column ) => {
		const header = Array.from( head ).findIndex( ( cell ) => cell.dataset.field === column.column );
		expect( sums[ header ].querySelector( `[data-menu="${ column.column }"]` ) ).not.toBeNull();
	} );
	// The selection and the actions columns have no sum and no menu.
	expect( sums[ 0 ].textContent ).toBe( '' );
	expect( sums[ sums.length - 1 ].textContent ).toBe( '' );
} );

test( 'the cell under the actions column is sticky like the column\'s own cells, so no sum shows through below the header', async () => {
	const container = await mount( true );
	const sums = container.querySelector( 'tr.kg-sumtr' ).children;
	const last = sums[ sums.length - 1 ];
	expect( last.classList.contains( 'kg-sumtd-actions' ) ).toBe( true );
	expect( last.classList.contains( 'dataviews-view-table__actions-column--sticky' ) ).toBe( true );
	// jsdom has no layout: the rule that makes the cell stick is read from the stylesheet. The real overlap is
	// measured in the browser (internal/spikes/mail-admin/overlap-check.cjs).
	const css = require( 'fs' ).readFileSync( 'plugins/kalaka/src/Admin/app/grid/grid.css', 'utf8' );
	expect( css ).toMatch( /tr\.kg-sumtr td\.kg-sumtd-actions \{ position: sticky; right: 0; \}/ );
} );

test( 'the cell under the actions column carries the divider class while DataViews\' own header cell does', async () => {
	const container = await mount( true );
	const sums = container.querySelector( 'tr.kg-sumtr' ).children;
	const last = sums[ sums.length - 1 ];
	expect( last.classList.contains( 'dataviews-view-table__actions-column--stuck' ) ).toBe( false );
	await act( async () => {
		container.querySelector( 'thead th.dataviews-view-table__actions-column' ).classList.add( 'dataviews-view-table__actions-column--stuck' );
	} );
	expect( last.classList.contains( 'dataviews-view-table__actions-column--stuck' ) ).toBe( true );
	await act( async () => {
		container.querySelector( 'thead th.dataviews-view-table__actions-column' ).classList.remove( 'dataviews-view-table__actions-column--stuck' );
	} );
	expect( last.classList.contains( 'dataviews-view-table__actions-column--stuck' ) ).toBe( false );
} );

test( 'the cell under the select-all checkbox holds the ticked count, and is empty while nothing is ticked', async () => {
	const none = await mount( true );
	expect( none.querySelector( 'tr.kg-sumtr' ).children[ 0 ].textContent ).toBe( '' );
	await act( async () => root.unmount() );
	root = null;
	none.remove();
	const container = await mount( true, 3 );
	const sums = container.querySelector( 'tr.kg-sumtr' ).children;
	expect( sums[ 0 ].querySelector( '[aria-hidden]' ).textContent ).toBe( '3' );
	expect( sums[ 0 ].querySelector( '.screen-reader-text' ).textContent ).toBe( '3 kiválasztva' );
	// Still one cell per header cell: the count does not move the sums.
	expect( sums ).toHaveLength( container.querySelector( 'thead tr:first-child' ).children.length );
	expect( sums[ 0 ].classList.contains( 'dataviews-view-table__checkbox-column' ) ).toBe( true );
} );

test( 'without row actions there is one cell per field and nothing else', async () => {
	const container = await mount( false );
	const head = container.querySelector( 'thead tr:first-child' ).children;
	expect( container.querySelector( 'tr.kg-sumtr' ).children ).toHaveLength( head.length );
	expect( container.querySelector( 'tr.kg-sumtr' ).children[ 0 ].querySelector( '[data-menu="code"]' ) ).not.toBeNull();
} );

test( 'each row checkbox is named with the registration\'s code, in the rows\' order', async () => {
	const container = table( true );
	labelRowCheckboxes( container, [ 'K1', 'K2' ], ( key ) => `Jelentkezés kijelölése: ${ key }` );
	const names = Array.from( container.querySelectorAll( 'tbody input' ) ).map( ( box ) => box.getAttribute( 'aria-label' ) );
	expect( names ).toEqual( [ 'Jelentkezés kijelölése: K1', 'Jelentkezés kijelölése: K2' ] );
} );

test( 'a table with no selection column is left alone', () => {
	const container = table( false );
	expect( () => labelRowCheckboxes( container, [ 'K1', 'K2' ], ( key ) => key ) ).not.toThrow();
} );
