import { createElement, useState } from '@wordpress/element';
import { createRoot } from 'react-dom/client';
import { act } from 'react';
import { NumberEdit, editFor } from '../../src/Admin/app/grid/NumberFilter';
import { narrowOf } from '../../src/Admin/app/grid/narrow';

// @wordpress/components is ESM-only for Jest here: plain stand-ins that render what the control hands them.
jest.mock( '@wordpress/components', () => {
	const { createElement: h } = require( '@wordpress/element' );
	return {
		BaseControl: ( { help, children } ) => h( 'div', null, children, h( 'p', { className: 'help' }, help ) ),
		Flex: ( { children } ) => h( 'div', null, children ),
		TextControl: ( { label, value, onChange, type, inputMode } ) =>
			h( 'input', { 'aria-label': label, value, type, inputMode, onChange: ( e ) => onChange( e.target.value ) } ),
	};
} );

globalThis.IS_REACT_ACT_ENVIRONMENT = true;

const FIELD = { id: 'field:nights', label: 'Éjszakák', getValue: ( { item } ) => item[ 'field:nights' ], setValue: ( { value } ) => ( { 'field:nights': value } ) };

/** Mounts the control like DataViews' filter does, with the filter's value kept in state; returns what it gave. */
function mount( Edit, operator ) {
	const gave = [];
	function Harness() {
		const [ data, setData ] = useState( {} );
		return createElement( Edit, {
			data,
			field: FIELD,
			operator,
			hideLabelFromVision: true,
			onChange: ( next ) => {
				gave.push( next[ 'field:nights' ] );
				setData( next );
			},
		} );
	}
	const host = document.createElement( 'div' );
	document.body.appendChild( host );
	const root = createRoot( host );
	act( () => root.render( createElement( Harness ) ) );
	const type = ( input, text ) =>
		act( () => {
			Object.getOwnPropertyDescriptor( window.HTMLInputElement.prototype, 'value' ).set.call( input, text );
			input.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
		} );
	return { host, gave, type, inputs: () => [ ...host.querySelectorAll( 'input' ) ], done: () => { act( () => root.unmount() ); host.remove(); } };
}

describe( 'a number filter’s control keeps the typed decimal comma (A4)', () => {
	test( 'it is a text input for decimals, not a number input', () => {
		const m = mount( NumberEdit, 'is' );
		const [ input ] = m.inputs();
		expect( input.type ).toBe( 'text' );
		expect( input.getAttribute( 'inputmode' ) ).toBe( 'decimal' );
		m.done();
	} );

	test( 'typing 2,5 gives 2.5, not 25, and the input still shows 2,5', () => {
		const m = mount( NumberEdit, 'is' );
		m.type( m.inputs()[ 0 ], '2,' );
		m.type( m.inputs()[ 0 ], '2,5' );
		expect( m.gave ).toEqual( [ 2, 2.5 ] );
		expect( m.inputs()[ 0 ].value ).toBe( '2,5' );
		// What the server is sent: a decimal string with a dot.
		expect( narrowOf( { filters: [ { field: 'field:nights', operator: 'is', value: m.gave.at( -1 ) } ] }, [ { column: 'field:nights', type: 'number' } ] ).filters ).toEqual( [
			{ field: 'field:nights', operator: 'is', value: '2.5' },
		] );
		m.done();
	} );

	test( 'a dot reads the same, and a text that is no number gives no value', () => {
		const m = mount( NumberEdit, 'lessThan' );
		m.type( m.inputs()[ 0 ], '3.25' );
		m.type( m.inputs()[ 0 ], 'abc' );
		expect( m.gave ).toEqual( [ 3.25, undefined ] );
		expect( m.inputs()[ 0 ].value ).toBe( 'abc' );
		m.done();
	} );

	test( 'a range has two inputs, gives [ min, max ] and keeps both typed texts', () => {
		const m = mount( NumberEdit, 'between' );
		expect( m.inputs() ).toHaveLength( 2 );
		m.type( m.inputs()[ 0 ], '1' );
		m.type( m.inputs()[ 1 ], '2,5' );
		expect( m.gave ).toEqual( [ [ 1, '' ], [ 1, 2.5 ] ] );
		expect( m.inputs().map( ( i ) => i.value ) ).toEqual( [ '1', '2,5' ] );
		m.done();
	} );

	test( 'a range has DataViews’ own help line only, on Szám too (N69)', () => {
		const plain = mount( NumberEdit, 'between' );
		expect( plain.host.querySelector( '.help' ).textContent ).toBe( 'The max. value must be greater than the min. value.' );
		plain.done();
		// Szám takes the same control as any number column, and has no help line of ours.
		expect( editFor( { type: 'serial' } ).Edit ).toBe( editFor( { type: 'number' } ).Edit );
		const serial = mount( editFor( { type: 'serial' } ).Edit, 'between' );
		expect( serial.host.querySelector( '.help' ).textContent ).toBe( 'The max. value must be greater than the min. value.' );
		expect( serial.host.textContent ).not.toContain( 'Például' );
		serial.done();
	} );
} );
