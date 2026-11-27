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
		DropdownMenu: ( { label, text, toggleProps, popoverProps, children } ) =>
			h(
				'div',
				{ 'data-shift': String( !! popoverProps.shift ) },
				h( 'button', { 'aria-label': label, className: toggleProps?.className }, text ),
				children( { onClose: () => mockClose() } )
			),
		MenuGroup: ( { label, children } ) => h( 'section', { 'data-group': label || '' }, children ),
		MenuItem: RealMenuItem,
	};
} );

const props = ( column, extra = {} ) => ( { column, chosen: [], onToggle: () => {}, ...extra } );
const html = ( column, extra = {} ) => renderToStaticMarkup( createElement( ColumnMenu, props( column, extra ) ) );
const STATUS = {
	column: 'status',
	label: 'Állapot',
	members: [ { value: 'registered', label: 'Rögzített' }, { value: 'waitlisted', label: 'Várólistás' } ],
};

/** The real `MenuItem` button that holds this label, as rendered. */
const item = ( markup, label ) => {
	const buttons = markup.match( /<button[^>]*role="menuitemcheckbox"[^>]*>.*?<\/button>/g ) || [];
	return buttons.find( ( b ) => b.includes( `>${ label }<` ) ) || '';
};

describe( 'a column’s counting menu', () => {
	test( 'its popover is shifted into the screen, so it is not cut off at the edge of a narrow window', () => {
		expect( html( STATUS ) ).toContain( 'data-shift="true"' );
	} );

	test( 'the number is the trigger, named after its column as a column menu', () => {
		const out = html( STATUS, { shown: '40' } );
		expect( out ).toContain( 'aria-label="Oszlopmenü: Állapot"' );
		expect( out ).toContain( '>40<' );
	} );

	test( 'there is no down arrow and no sort command: the owner kept DataViews’ own header menu', () => {
		const out = html( STATUS, { shown: '40' } );
		expect( out ).not.toContain( 'chevronDown' );
		expect( out ).not.toContain( 'Sort ascending' );
		expect( out ).not.toContain( 'Jelentkezés' );
	} );

	test( 'a countable column with nothing counted yet shows a grey placeholder', () => {
		const out = html( STATUS );
		expect( out ).toContain( 'is-empty' );
		expect( out ).toContain( '–' );
	} );

	test( 'a column that counts lists its members as checkbox items, ticked as chosen', () => {
		const out = html( STATUS, { chosen: [ 'registered' ] } );
		expect( out ).toContain( 'data-group="Számolás"' );
		expect( item( out, 'Rögzített' ) ).toContain( 'aria-checked="true"' );
		expect( item( out, 'Várólistás' ) ).toContain( 'aria-checked="false"' );
	} );

	test( 'the counted members show a check mark, as WordPress’s own checkable menu items do', () => {
		const out = html( STATUS, { chosen: [ 'registered' ] } );
		expect( item( out, 'Rögzített' ) ).toContain( '<svg' );
		expect( item( out, 'Rögzített' ) ).toContain( 'M7 12L10 15L17 8' );
		expect( item( out, 'Várólistás' ) ).not.toContain( '<svg' );
	} );

	test( 'a tick keeps the menu open, and the pointer leaving it closes it', () => {
		const { createRoot } = require( 'react-dom/client' );
		const { act } = require( 'react' );
		mockClose = jest.fn();
		const onToggle = jest.fn();
		const host = document.createElement( 'div' );
		document.body.appendChild( host );
		const root = createRoot( host );
		globalThis.IS_REACT_ACT_ENVIRONMENT = true;
		act( () =>
			root.render( createElement( ColumnMenu, props( STATUS, { onToggle, chosen: [ 'registered' ] } ) ) )
		);
		const waitlisted = [ ...host.querySelectorAll( 'button[role="menuitemcheckbox"]' ) ].find(
			( n ) => n.textContent === 'Várólistás'
		);
		act( () => waitlisted.click() );
		expect( onToggle ).toHaveBeenCalledWith( STATUS, 'waitlisted', true );
		expect( mockClose ).not.toHaveBeenCalled();
		// Focus outside the menu (the toggle), so a pointer leaving it closes it.
		act( () => host.querySelector( '.kg-sumcount-btn' ).focus() );
		const menu = host.querySelector( '.kg-sumcount-menu' );
		act( () =>
			menu.dispatchEvent(
				new window.MouseEvent( 'mouseout', { bubbles: true, relatedTarget: document.body } )
			)
		);
		expect( mockClose ).toHaveBeenCalledTimes( 1 );
		act( () => root.unmount() );
		host.remove();
	} );
} );
