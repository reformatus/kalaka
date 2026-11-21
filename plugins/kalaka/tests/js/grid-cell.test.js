import { createElement } from '@wordpress/element';
import { renderToStaticMarkup } from 'react-dom/server';
import Cell, { isInactive } from '../../src/Admin/app/grid/Cell';
import fs from 'fs';
import path from 'path';

const columns = {
	code: { column: 'code', type: 'code' },
	name: { column: 'field:name', type: 'text' },
	status: { column: 'status', type: 'status', options: { registered: 'Rögzített', waitlisted: 'Várólistás' } },
	number: { column: 'field:n', type: 'number', numeric: true },
	decision: { column: 'decision:c', type: 'decision', states: { yes: 'Igen' } },
};
const row = ( status ) => ( {
	values: { code: '00A0', 'field:name': 'Kiss Eszter', status, 'field:n': '3', 'decision:c': 'yes' },
} );
const html = ( column, item ) => renderToStaticMarkup( createElement( Cell, { column, item } ) );

describe( 'a dimmed row', () => {
	test.each( [ 'waitlisted', 'withdrawn', 'removed' ] )( 'a %s registration dims every cell of its row', ( status ) => {
		expect( isInactive( row( status ) ) ).toBe( true );
		for ( const column of Object.values( columns ) ) {
			const out = html( column, row( status ) );
			expect( out ).toContain( 'kg-inactive' );
			expect( out ).not.toContain( 'kg-active' );
		}
	} );

	test( 'a registered row is active in every cell, so the dimming is the contrast between the two', () => {
		expect( isInactive( row( 'registered' ) ) ).toBe( false );
		for ( const column of Object.values( columns ) ) {
			const out = html( column, row( 'registered' ) );
			expect( out ).toContain( 'kg-active' );
			expect( out ).not.toContain( 'kg-inactive' );
		}
	} );

	test( 'a row that carries no status (a view without Állapot) is never dimmed', () => {
		const item = { values: { code: '00A0', 'field:name': 'Kiss Eszter' } };
		expect( isInactive( item ) ).toBe( false );
		expect( html( columns.name, item ) ).toContain( 'kg-active' );
	} );

	test( 'the stylesheet makes an inactive row visibly lighter than an active one, in DataViews’ own tokens', () => {
		// The bug of the owner's walk: the "dimmed" colour was darker than DataViews' own body text, so nothing looked dimmed.
		// eslint-disable-next-line no-undef
		const css = fs.readFileSync( path.join( process.cwd(), 'plugins/kalaka/src/Admin/app/grid/grid.css' ), 'utf8' );
		expect( css ).toMatch( /\.kg-active \{ color: var\(--wpds-color-foreground-content-neutral, #1e1e1e\); \}/ );
		expect( css ).toMatch( /\.kg-inactive \{ color: var\(--wpds-color-foreground-content-neutral-weak, #707070\); \}/ );
		expect( css ).not.toContain( '#646970' );
	} );
} );
