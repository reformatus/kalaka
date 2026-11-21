import { createElement } from '@wordpress/element';
import { renderToStaticMarkup } from 'react-dom/server';
import ColumnMenu from '../../src/Admin/app/grid/ColumnMenu';

// @wordpress/components is ESM-only for Jest here: a stand-in that renders what ColumnMenu hands it.
jest.mock( '@wordpress/components', () => {
	const { createElement: h } = require( '@wordpress/element' );
	return {
		DropdownMenu: ( { label, toggleProps, children } ) =>
			h( 'div', null, h( 'button', { 'aria-label': label, disabled: toggleProps.disabled } ), children( {} ) ),
		MenuGroup: ( { label, children } ) => h( 'section', { 'data-group': label || '' }, children ),
		MenuItem: ( { role, isSelected, children } ) =>
			h( 'div', { role, 'aria-checked': String( !! isSelected ) }, children ),
		MenuItemsChoice: ( { choices, value } ) =>
			choices.map( ( c ) =>
				h( 'div', { key: c.value, role: 'menuitemradio', 'aria-checked': String( value === c.value ) }, c.label, ' ', c.info || '' )
			),
	};
} );

const html = ( column, props = {} ) =>
	renderToStaticMarkup(
		createElement( ColumnMenu, { column, view: {}, onSort: () => {}, chosen: [], onToggle: () => {}, ...props } )
	);

describe( 'a column’s menu', () => {
	test( 'every column has its arrow, named after the column', () => {
		for ( const column of [
			{ column: 'status', label: 'Állapot', sortable: true, members: [ { value: 'registered', label: 'Rögzített' } ] },
			{ column: 'field:note', label: 'Megjegyzés', sortable: true },
			{ column: 'code', label: 'Kód', sortable: true },
		] ) {
			expect( html( column ) ).toContain( `aria-label="${ column.label } oszlopmenüje"` );
		}
	} );

	test( 'a column with nothing to offer shows a disabled arrow', () => {
		expect( html( { column: 'field:time', label: 'Érkezés', sortable: false } ) ).toMatch( /<button[^>]*disabled/ );
		expect( html( { column: 'field:note', label: 'Megjegyzés', sortable: true } ) ).not.toMatch( /<button[^>]*disabled/ );
	} );

	test( 'a column that counts lists its members as checkbox items, ticked as chosen', () => {
		const status = {
			column: 'status',
			label: 'Állapot',
			sortable: true,
			members: [ { value: 'registered', label: 'Rögzített' }, { value: 'waitlisted', label: 'Várólistás' } ],
		};
		const out = html( status, { chosen: [ 'registered' ] } );
		expect( out ).toContain( 'data-group="Számolás"' );
		expect( out ).toContain( 'role="menuitemcheckbox" aria-checked="true">Rögzített' );
		expect( out ).toContain( 'role="menuitemcheckbox" aria-checked="false">Várólistás' );
		expect( html( { column: 'field:note', label: 'Megjegyzés', sortable: true } ) ).not.toContain( 'Számolás' );
	} );

	test( 'the active sort direction is checked, and only Kód carries the hint on its sort options', () => {
		const code = html( { column: 'code', label: 'Kód', sortable: true }, { view: { sort: { field: 'code', direction: 'asc' } } } );
		expect( code ).toMatch( /aria-checked="true">[^<]*Jelentkezés sorrendjében/ );
		expect( code ).toContain( 'Jelentkezés fordított sorrendjében' );
		const other = html( { column: 'field:note', label: 'Megjegyzés', sortable: true } );
		expect( other ).not.toContain( 'Jelentkezés' );
	} );
} );
