import { createElement } from '@wordpress/element';
import { renderToStaticMarkup } from 'react-dom/server';
import ColumnMenu from '../../src/Admin/app/grid/ColumnMenu';

// The package index of @wordpress/components pulls in ESM-only code that Jest cannot load here, so it is
// stood in for. `MenuItem` is the real component, though (loaded from its own build file), so what is asserted
// about a menu item is what WordPress renders; the rest are stand-ins that render what ColumnMenu hands them.
let mockClose;
jest.mock( '@wordpress/components', () => {
	const { createElement: h } = require( '@wordpress/element' );
	const RealMenuItem = require( '../../../../node_modules/@wordpress/components/build/menu-item/index.cjs' ).default;
	return {
		DropdownMenu: ( { label, toggleProps, popoverProps, children } ) =>
			h( 'div', { 'data-shift': String( !! popoverProps.shift ) }, h( 'button', { 'aria-label': label, disabled: toggleProps.disabled } ), children( { onClose: () => mockClose() } ) ),
		MenuGroup: ( { label, children } ) => h( 'section', { 'data-group': label || '' }, children ),
		MenuItem: RealMenuItem,
		MenuItemsChoice: ( { choices, value, onSelect } ) =>
			choices.map( ( c ) =>
				h(
					'div',
					{ key: c.value, role: 'menuitemradio', 'aria-checked': String( value === c.value ), onClick: () => onSelect( c.value ) },
					c.label,
					' ',
					c.info || ''
				)
			),
	};
} );

const props = ( column, extra = {} ) => ( { column, view: {}, onSort: () => {}, chosen: [], onToggle: () => {}, ...extra } );
const html = ( column, extra = {} ) => renderToStaticMarkup( createElement( ColumnMenu, props( column, extra ) ) );
const STATUS = {
	column: 'status',
	label: 'Állapot',
	sortable: true,
	members: [ { value: 'registered', label: 'Rögzített' }, { value: 'waitlisted', label: 'Várólistás' } ],
};

/** The real `MenuItem` button that holds this label, as rendered. */
const item = ( markup, label ) => {
	const buttons = markup.match( /<button[^>]*role="menuitemcheckbox"[^>]*>.*?<\/button>/g ) || [];
	return buttons.find( ( b ) => b.includes( `>${ label }<` ) ) || '';
};

describe( 'a column’s menu', () => {
	test( 'its popover is shifted into the screen, so it is not cut off at the edge of a narrow window', () => {
		expect( html( STATUS ) ).toContain( 'data-shift="true"' );
	} );

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
		const out = html( STATUS, { chosen: [ 'registered' ] } );
		expect( out ).toContain( 'data-group="Számolás"' );
		expect( item( out, 'Rögzített' ) ).toContain( 'aria-checked="true"' );
		expect( item( out, 'Várólistás' ) ).toContain( 'aria-checked="false"' );
		expect( html( { column: 'field:note', label: 'Megjegyzés', sortable: true } ) ).not.toContain( 'Számolás' );
	} );

	test( 'the active sort direction is checked, and only Kód carries the hint on its sort options', () => {
		const code = html( { column: 'code', label: 'Kód', sortable: true }, { view: { sort: { field: 'code', direction: 'asc' } } } );
		expect( code ).toMatch( /aria-checked="true">[^<]*Jelentkezés sorrendjében/ );
		expect( code ).toContain( 'Jelentkezés fordított sorrendjében' );
		const other = html( { column: 'field:note', label: 'Megjegyzés', sortable: true } );
		expect( other ).not.toContain( 'Jelentkezés' );
	} );

	test( 'the counted members show a check mark, as WordPress’s own checkable menu items do', () => {
		const out = html( STATUS, { chosen: [ 'registered' ] } );
		expect( item( out, 'Rögzített' ) ).toContain( '<svg' );
		expect( item( out, 'Rögzített' ) ).toContain( 'M7 12L10 15L17 8' );
		expect( item( out, 'Várólistás' ) ).not.toContain( '<svg' );
		const both = html( STATUS, { chosen: [ 'registered', 'waitlisted' ] } );
		expect( item( both, 'Várólistás' ) ).toContain( '<svg' );
	} );

	test( 'the arrow is a small button with an icon from the icon package, not a dashicon', () => {
		expect( html( STATUS ) ).toContain( 'aria-label="Állapot oszlopmenüje"' );
		expect( ColumnMenu.toString() ).not.toContain( 'arrow-down-alt2' );
	} );

	test( 'a sort click closes the menu, a counting toggle keeps it open', () => {
		const { createRoot } = require( 'react-dom/client' );
		const { act } = require( 'react' );
		mockClose = jest.fn();
		const onSort = jest.fn();
		const onToggle = jest.fn();
		const host = document.createElement( 'div' );
		document.body.appendChild( host );
		const root = createRoot( host );
		globalThis.IS_REACT_ACT_ENVIRONMENT = true;
		act( () => root.render( createElement( ColumnMenu, props( STATUS, { onSort, onToggle, chosen: [ 'registered' ] } ) ) ) );
		const asc = [ ...host.querySelectorAll( '[role="menuitemradio"]' ) ].find( ( n ) => n.textContent.includes( 'Sort ascending' ) );
		act( () => asc.click() );
		expect( onSort ).toHaveBeenCalledWith( { field: 'status', direction: 'asc' } );
		expect( mockClose ).toHaveBeenCalledTimes( 1 );
		const waitlisted = [ ...host.querySelectorAll( 'button[role="menuitemcheckbox"]' ) ].find( ( n ) => n.textContent === 'Várólistás' );
		act( () => waitlisted.click() );
		expect( onToggle ).toHaveBeenCalledWith( STATUS, 'waitlisted', true );
		expect( mockClose ).toHaveBeenCalledTimes( 1 );
		act( () => root.unmount() );
		host.remove();
	} );
} );
