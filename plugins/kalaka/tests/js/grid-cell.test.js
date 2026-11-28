import { createElement } from '@wordpress/element';
import { renderToStaticMarkup } from 'react-dom/server';
import Cell, { isInactive } from '../../src/Admin/app/grid/Cell';
import fs from 'fs';
import path from 'path';

jest.mock( '@wordpress/components', () => {
	const { createElement: h } = require( 'react' );
	return { VisuallyHidden: ( { children } ) => h( 'span', { className: 'vh' }, children ) };
} );

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
		}
	} );

	test( 'a registered row carries no dimming class, so it keeps DataViews’ own text colour', () => {
		expect( isInactive( row( 'registered' ) ) ).toBe( false );
		for ( const column of Object.values( columns ) ) {
			const out = html( column, row( 'registered' ) );
			expect( out ).not.toContain( 'kg-inactive' );
			expect( out ).not.toContain( 'kg-active' );
		}
	} );

	test( 'a row that carries no status (a view without Állapot) is never dimmed', () => {
		const item = { values: { code: '00A0', 'field:name': 'Kiss Eszter' } };
		expect( isInactive( item ) ).toBe( false );
		expect( html( columns.name, item ) ).not.toContain( 'kg-inactive' );
	} );

	test( 'the stylesheet dims only the inactive rows, in the design system’s disabled token, and leaves active text alone', () => {
		// The bug of the owner's walk: the "dimmed" colour was darker than DataViews' own body text, so nothing looked dimmed.
		// eslint-disable-next-line no-undef
		const css = fs.readFileSync( path.join( process.cwd(), 'plugins/kalaka/src/Admin/app/grid/grid.css' ), 'utf8' );
		expect( css ).toMatch( /\.kg-inactive \{ color: var\(--wpds-color-foreground-interactive-neutral-disabled, #8d8d8d\); \}/ );
		expect( css ).not.toContain( 'kg-active' );
		expect( css ).not.toContain( '#646970' );
	} );
} );

describe( 'the awaits cell (owner rulings 6.2/6.3)', () => {
	const awaits = { column: 'awaits_answer', type: 'consent', options: { 1: 'Igen', 0: 'Nem' }, na: '–' };
	const cell = ( value ) => html( awaits, { values: { status: 'waitlisted', awaits_answer: value } } );

	test( 'no value shows the meta’s „–” with a hidden „Nem értelmezhető”, never „Nem”', () => {
		for ( const value of [ null, undefined ] ) {
			const out = cell( value );
			expect( out ).toContain( '<span aria-hidden="true">–</span>' );
			expect( out ).toContain( 'Nem értelmezhető' );
			expect( out ).not.toMatch( />Nem</ );
		}
	} );

	test( 'a value shows Igen or Nem as before, without the spoken text', () => {
		expect( cell( '1' ) ).not.toContain( 'Nem értelmezhető' );
		expect( cell( '0' ) ).toContain( 'Nem' );
		expect( cell( '0' ) ).not.toContain( 'Nem értelmezhető' );
	} );

	test( 'the „–” comes from the meta’s na, not a literal', () => {
		expect( html( { ...awaits, na: '?' }, { values: { awaits_answer: null } } ) ).toContain( '>?</span>' );
	} );
} );

describe( 'the confirmation column', () => {
	const confirmation = {
		column: 'decision:confirmation',
		type: 'decision',
		states: { confirmed: 'Visszaigazolva', unconfirmed: 'Nincs visszaigazolva' },
		marks: { confirmed: { icon: 'yes-alt', tone: 'ok' }, unconfirmed: { icon: 'marker', tone: '' } },
	};
	const state = ( value ) => ( { values: { code: '00A0', 'decision:confirmation': value } } );

	test( 'a confirmed state draws the confirmed mark, an unconfirmed one the plain marker', () => {
		expect( html( confirmation, state( 'confirmed' ) ) ).toContain( 'dashicons-yes-alt kg-ok' );
		expect( html( confirmation, state( 'unconfirmed' ) ) ).toContain( 'dashicons-marker' );
	} );

	test( 'a decision kind with no declared marks shows the state text and no icon', () => {
		const out = html( columns.decision, row( 'registered' ) );
		expect( out ).not.toContain( 'dashicons-' );
		expect( out ).toContain( 'Igen' );
	} );
} );
