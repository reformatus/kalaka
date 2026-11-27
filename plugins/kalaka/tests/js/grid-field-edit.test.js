/**
 * Staff editing of field values in the detail panel (B5-2 on #249): the list and its buttons from `writable`
 * only, the control the read names (`edit.control`, `edit.multiple`), the value each kind sends, `expected` sent
 * back as read, the "full" warning from the preview (no reason) before the save (placing never stopped), the reason rule, a conflict shown with the server's text and the re-read, the
 * generic failure, `changed: false` as done, and the double submit.
 */
import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';
import FieldEditDialog from '../../src/Admin/app/grid/FieldEditDialog';
import FieldsBlock from '../../src/Admin/app/grid/FieldsBlock';
import { controlOf, inputOf, isFull, openingOf, valueOf } from '../../src/Admin/app/grid/fieldEdit';
import { CONFIRM, FIELDS, GRID, MONEY, REQUESTS } from '../../src/Admin/app/grid/texts';

jest.mock( '@wordpress/components', () => {
	const { createElement: h } = require( 'react' );
	const TextareaControl = ( { label, value, onChange, help } ) =>
		h( 'label', null, label, h( 'textarea', { 'aria-label': label, value, onChange: ( e ) => onChange( e.target.value ) } ), help );
	const TextControl = ( { label, value, onChange, type } ) =>
		h( 'input', { 'aria-label': label, type, value, onChange: ( e ) => onChange( e.target.value ) } );
	const CheckboxControl = ( { label, checked, onChange } ) =>
		h( 'label', null, h( 'input', { type: 'checkbox', checked, onChange: ( e ) => onChange( e.target.checked ) } ), label );
	const RadioControl = ( { options, selected, onChange } ) =>
		h(
			'div',
			null,
			options.map( ( o ) =>
				h( 'label', { key: o.value }, h( 'input', { type: 'radio', name: 'r', value: o.value, checked: selected === o.value, onChange: () => onChange( o.value ) } ), o.label )
			)
		);
	const Button = ( { children, ...rest } ) => {
		delete rest.isBusy;
		delete rest.variant;
		delete rest.size;
		return h( 'button', rest, children );
	};
	const Modal = ( { title, children } ) => h( 'div', { role: 'dialog' }, h( 'h1', null, title ), children );
	const Notice = ( { children, status } ) => h( 'div', { 'data-notice': status }, children );
	return { Button, Modal, Notice, TextareaControl, TextControl, CheckboxControl, RadioControl, SnackbarList: () => null };
} );

jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: jest.fn() } ), { virtual: true } );

window.IS_REACT_ACT_ENVIRONMENT = true;

const config = { rest: '', event: 7 };
const FULL_TEXT = 'Ez a lehetőség már betelt. Ha ide sorolod a jelentkezőt, a létszám túllépi a keretet.';
const NAME = { key: 'name', label: 'Név', type: 'kalaka/name-field', value: 'Próba', text: 'Próba', writable: true, edit: { answer: { kind: 'kalaka/name-field', value: 'Próba' }, expected: 'Próba', control: 'text' } };
const AGE = { key: 'kor', label: 'Kor', text: '12', writable: true, edit: { answer: { kind: 'kalaka/number-field', value: '12' }, expected: '12', control: 'number' } };
const DAY = { key: 'szul', label: 'Születés', text: '2014-05-01', writable: true, edit: { answer: { kind: 'kalaka/date-field', value: '2014-05-01' }, expected: '2014-05-01', control: 'date' } };
const TIER = {
	key: 'price_tier',
	label: 'Díjkategória',
	text: 'Támogatott ár',
	writable: true,
	edit: {
		answer: { kind: 'kalaka/choice-field', value: 'tamogatott' },
		expected: 'tamogatott',
		control: 'choice',
		multiple: false,
		options: [ { value: 'alap', label: 'Alap ár', full: false }, { value: 'tamogatott', label: 'Támogatott ár', full: true } ],
	},
};
const OFFER = { key: 'tamogatas', label: 'Gyülekezeti támogatás', text: '', writable: true, edit: { answer: { kind: 'kalaka/consent-field', value: null }, expected: null, control: 'consent', full: true } };
const LOCKED = { key: 'tamogatas_answer', label: 'Válasz', text: '', writable: false };
const RANKING = { key: 'rang', label: 'Rang', text: 'a', writable: true, edit: { answer: { kind: 'kalaka/ranking-field', value: [ 'a' ] }, expected: '["a"]', control: null, options: [] } };

let host;
let root;
let done;
let reload;
async function mount( element ) {
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
	await act( async () => root.render( element ) );
}
const dialog = ( field ) => {
	done = jest.fn().mockResolvedValue( undefined );
	reload = jest.fn();
	return mount( createElement( FieldEditDialog, { config, code: '01F4', field, onClose: () => {}, onDone: done, reload } ) );
};
const textarea = () => host.querySelector( 'textarea' );
async function type( element, value ) {
	const proto = element.tagName === 'TEXTAREA' ? window.HTMLTextAreaElement.prototype : window.HTMLInputElement.prototype;
	await act( async () => {
		Object.getOwnPropertyDescriptor( proto, 'value' ).set.call( element, value );
		element.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
}
async function click( element ) {
	await act( async () => element.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) ) );
}
async function submit() {
	await act( async () => host.querySelector( 'form' ).dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) ) );
}
const warnings = () => Array.from( host.querySelectorAll( '[data-notice="warning"]' ) ).map( ( n ) => n.textContent );
const errors = () => Array.from( host.querySelectorAll( '[data-notice="error"]' ) ).map( ( n ) => n.textContent );

beforeEach( () => apiFetch.mockReset() );
afterEach( async () => {
	if ( root ) {
		await act( async () => root.unmount() );
		root = null;
		host.remove();
	}
} );

describe( 'which fields get a control', () => {
	test( 'only writable fields with a kind the panel can edit', () => {
		expect( controlOf( NAME ) ).toBe( 'text' );
		expect( controlOf( TIER ) ).toBe( 'choice' );
		expect( controlOf( OFFER ) ).toBe( 'consent' );
		expect( controlOf( AGE ) ).toBe( 'number' );
		expect( controlOf( DAY ) ).toBe( 'date' );
		// A choice taking several options: the read says so (`multi_choice`, or `multiple`), the stored value is not looked at.
		const multi = { ...TIER, edit: { ...TIER.edit, control: 'multi_choice', multiple: true, answer: { kind: TIER.edit.answer.kind, value: null } } };
		expect( controlOf( multi ) ).toBe( 'multi_choice' );
		expect( controlOf( { ...TIER, edit: { ...TIER.edit, multiple: true } } ) ).toBe( 'multi_choice' );
		expect( openingOf( multi ) ).toEqual( [] );
		expect( controlOf( { ...TIER, edit: { ...TIER.edit, options: undefined } } ) ).toBeNull();
		expect( controlOf( { ...NAME, edit: { ...NAME.edit, control: 'ranking' } } ) ).toBeNull();
		expect( controlOf( LOCKED ) ).toBeNull();
		expect( controlOf( RANKING ) ).toBeNull();
		expect( controlOf( { ...NAME, writable: false } ) ).toBeNull();
	} );

	test( 'the list shows every field the read carries and an edit button only on the editable ones', async () => {
		await mount( createElement( FieldsBlock, { config, code: 'x', detail: { fields: [ NAME, LOCKED, RANKING, OFFER ] }, stale: false, reload: () => {}, onSaved: () => {} } ) );
		expect( host.querySelectorAll( 'dt' ) ).toHaveLength( 4 );
		expect( Array.from( host.querySelectorAll( 'button' ) ).map( ( b ) => b.getAttribute( 'aria-label' ) ) ).toEqual( [ 'Szerkesztés: Név', 'Szerkesztés: Gyülekezeti támogatás' ] );
		expect( host.querySelectorAll( 'dd' )[ 1 ].textContent ).toBe( FIELDS.empty );
	} );

	test( 'no list without fields, no button while stale', async () => {
		await mount( createElement( FieldsBlock, { config, code: 'x', detail: { fields: [] }, stale: false, reload: () => {}, onSaved: () => {} } ) );
		expect( host.innerHTML ).toBe( '' );
		await act( async () => root.render( createElement( FieldsBlock, { config, code: 'x', detail: { fields: [ NAME ] }, stale: true, reload: () => {}, onSaved: () => {} } ) ) );
		expect( host.querySelectorAll( 'button' ) ).toHaveLength( 0 );
	} );
} );

describe( 'what a control opens on and sends', () => {
	test( 'opens on edit.answer and sends expected as read, never rebuilt from the answer', () => {
		expect( openingOf( NAME ) ).toBe( 'Próba' );
		expect( openingOf( OFFER ) ).toBe( false );
		const stored = { ...TIER, edit: { ...TIER.edit, expected: '["tamogatott"]' } };
		expect( inputOf( 7, 'A', stored, 'alap', 'ok' ) ).toEqual( { event_id: 7, code: 'A', field: 'price_tier', value: 'alap', expected: '["tamogatott"]', reason: 'ok' } );
		expect( inputOf( 7, 'A', OFFER, true, 'ok' ).expected ).toBeNull();
	} );

	test( 'a preview carries no reason', () => {
		expect( inputOf( 7, 'A', TIER, 'alap', '', true ) ).toEqual( { event_id: 7, code: 'A', field: 'price_tier', value: 'alap', expected: 'tamogatott', preview: true } );
	} );

	test( 'a clear is null; a consent is "1" or "0"', () => {
		expect( valueOf( NAME, '' ) ).toBeNull();
		expect( valueOf( TIER, '' ) ).toBeNull();
		expect( valueOf( OFFER, true ) ).toBe( '1' );
		expect( valueOf( OFFER, false ) ).toBe( '0' );
	} );

	test( 'full only for a picked full option or a ticked full consent', () => {
		expect( isFull( TIER, 'tamogatott' ) ).toBe( true );
		expect( isFull( TIER, 'alap' ) ).toBe( false );
		expect( isFull( OFFER, true ) ).toBe( true );
		expect( isFull( OFFER, false ) ).toBe( false );
		expect( isFull( NAME, 'x' ) ).toBe( false );
	} );
} );

describe( 'the dialog', () => {
	test( 'runs edit-field-value with the trimmed reason and hands the answer on', async () => {
		apiFetch.mockResolvedValue( { changed: true, over_limit: [] } );
		await dialog( NAME );
		await type( host.querySelector( 'input' ), 'Új Név' );
		await type( textarea(), '  Elírás  ' );
		await submit();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		const request = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( request.path ).toBe( '/wp-abilities/v1/abilities/kalaka/edit-field-value/run' );
		expect( request.data.input ).toEqual( { event_id: 7, code: '01F4', field: 'name', value: 'Új Név', expected: 'Próba', reason: 'Elírás' } );
		expect( done ).toHaveBeenCalledWith( { changed: true, over_limit: [] } );
	} );

	test( 'the reason is optional and at most 500 characters', async () => {
		await dialog( NAME );
		await type( textarea(), 'a'.repeat( 501 ) );
		await submit();
		expect( apiFetch ).not.toHaveBeenCalled();
		expect( host.textContent ).toContain( CONFIRM.reasonTooLong );
	} );

	test( 'a full option asks the preview without a reason and shows its warning text; the save is not stopped', async () => {
		apiFetch.mockImplementation( ( request ) =>
			Promise.resolve( request.data.input.preview ? { changed: true, over_limit: [ 'price_tier' ], warnings: [ FULL_TEXT ] } : { changed: true, over_limit: [ 'price_tier' ] } )
		);
		const field = { ...TIER, edit: { ...TIER.edit, answer: { kind: TIER.edit.answer.kind, value: 'alap' }, expected: 'alap' } };
		await dialog( field );
		expect( warnings() ).toEqual( [] );
		expect( apiFetch ).not.toHaveBeenCalled();
		await click( host.querySelector( 'input[value="tamogatott"]' ) );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data.input ).toEqual( { event_id: 7, code: '01F4', field: 'price_tier', value: 'tamogatott', expected: 'alap', preview: true } );
		expect( warnings() ).toEqual( [ FULL_TEXT ] );
		await click( host.querySelector( 'input[value="alap"]' ) );
		expect( warnings() ).toEqual( [] );
		await click( host.querySelector( 'input[value="tamogatott"]' ) );
		await type( textarea(), 'Kérte' );
		await submit();
		const save = apiFetch.mock.calls.map( ( call ) => call[ 0 ] ).filter( ( request ) => ! request.data.input.preview );
		expect( save ).toHaveLength( 1 );
		expect( save[ 0 ].data.input ).toMatchObject( { value: 'tamogatott', expected: 'alap', reason: 'Kérte' } );
		expect( done ).toHaveBeenCalled();
	} );

	test( 'a failed preview shows no warning and does not stop the save', async () => {
		apiFetch.mockImplementation( ( request ) =>
			request.data.input.preview ? Promise.reject( { code: 'kalaka_version_conflict', message: 'x' } ) : Promise.resolve( { changed: true, over_limit: [] } )
		);
		await dialog( { ...TIER, edit: { ...TIER.edit, answer: { kind: TIER.edit.answer.kind, value: 'alap' }, expected: 'alap' } } );
		await click( host.querySelector( 'input[value="tamogatott"]' ) );
		expect( warnings() ).toEqual( [] );
		await type( textarea(), 'Kérte' );
		await submit();
		expect( done ).toHaveBeenCalled();
	} );

	test( 'a number control is a number input and a date is a date input', async () => {
		await dialog( AGE );
		expect( host.querySelector( 'input' ).getAttribute( 'type' ) ).toBe( 'number' );
		await act( async () => root.unmount() );
		host.remove();
		await dialog( DAY );
		expect( host.querySelector( 'input' ).getAttribute( 'type' ) ).toBe( 'date' );
	} );

	test( 'a date control opens on the stored day, sends YYYY-MM-DD and sends null when emptied [N1235]', async () => {
		apiFetch.mockResolvedValue( { changed: true, over_limit: [] } );
		await dialog( DAY );
		const input = host.querySelector( 'input' );
		expect( input.value ).toBe( '2014-05-01' );
		await type( input, '2015-06-02' );
		await submit();
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data.input.value ).toBe( '2015-06-02' );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data.input.expected ).toBe( '2014-05-01' );
		await act( async () => root.unmount() );
		host.remove();
		apiFetch.mockClear();
		await dialog( DAY );
		await type( host.querySelector( 'input' ), '' );
		await submit();
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data.input.value ).toBeNull();
	} );

	test( 'a multiple choice shows checkboxes and sends a list, null when none is left', async () => {
		apiFetch.mockResolvedValue( { changed: true, over_limit: [] } );
		const multi = { ...TIER, edit: { ...TIER.edit, control: 'multi_choice', multiple: true, answer: { kind: TIER.edit.answer.kind, value: null }, expected: null } };
		await dialog( multi );
		expect( host.querySelectorAll( 'input[type="checkbox"]' ) ).toHaveLength( 2 );
		await click( host.querySelector( 'input[type="checkbox"]' ) );
		await type( textarea(), 'Kérte' );
		await submit();
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data.input ).toMatchObject( { value: [ 'alap' ], expected: null } );
	} );

	test( 'a single choice can be cleared through the first option', async () => {
		apiFetch.mockResolvedValue( { changed: true, over_limit: [] } );
		await dialog( TIER );
		expect( host.querySelector( 'input[value=""]' ).parentElement.textContent ).toBe( GRID.unanswered );
		await click( host.querySelector( 'input[value=""]' ) );
		await type( textarea(), 'Töröl' );
		await submit();
		const save = apiFetch.mock.calls.map( ( call ) => call[ 0 ] ).filter( ( request ) => ! request.data.input.preview );
		expect( save[ 0 ].data.input.value ).toBeNull();
	} );

	test( 'focus opens on the checked option of a choice, not on the clear option', async () => {
		apiFetch.mockResolvedValue( { warnings: [] } );
		await dialog( TIER );
		await act( async () => new Promise( ( resolve ) => setTimeout( resolve, 120 ) ) );
		expect( document.activeElement ).toBe( host.querySelector( 'input[value="tamogatott"]' ) );
	} );

	test( 'focus opens on the first control when nothing is checked', async () => {
		await dialog( NAME );
		await act( async () => new Promise( ( resolve ) => setTimeout( resolve, 120 ) ) );
		expect( document.activeElement ).toBe( host.querySelector( 'input' ) );
	} );

	test( 'a consent\'s label is not shown twice: the title is the field, the checkbox says Igen', async () => {
		apiFetch.mockResolvedValue( { warnings: [] } );
		await dialog( OFFER );
		expect( host.querySelector( 'h1' ).textContent ).toBe( OFFER.label );
		expect( host.querySelector( 'input[type="checkbox"]' ).parentElement.textContent ).toBe( REQUESTS.yes );
		expect( host.textContent.split( OFFER.label ).length - 1 ).toBe( 1 );
	} );

	test( 'a consent sends "1"', async () => {
		apiFetch.mockResolvedValue( { changed: true, over_limit: [] } );
		await dialog( OFFER );
		await click( host.querySelector( 'input[type="checkbox"]' ) );
		await type( textarea(), 'Kérte' );
		await submit();
		const save = apiFetch.mock.calls.map( ( call ) => call[ 0 ] ).filter( ( request ) => ! request.data.input.preview );
		expect( save[ 0 ].data.input ).toMatchObject( { field: 'tamogatas', value: '1', expected: null } );
	} );

	test( 'a conflict shows the server text, keeps the dialog and the reason, and re-reads', async () => {
		apiFetch.mockRejectedValue( { code: 'kalaka_version_conflict', message: 'Valaki időközben módosította ezeket az adatokat. Töltsd újra az oldalt, és próbáld újra.' } );
		await dialog( NAME );
		await type( textarea(), 'Elírás' );
		await submit();
		expect( errors() ).toEqual( [ 'Valaki időközben módosította ezeket az adatokat. Töltsd újra az oldalt, és próbáld újra.' ] );
		expect( reload ).toHaveBeenCalled();
		expect( done ).not.toHaveBeenCalled();
		expect( textarea().value ).toBe( 'Elírás' );
	} );

	test( 'any other failure is the generic text, never a raw message', async () => {
		apiFetch.mockRejectedValue( { code: 'rest_no_route', message: 'No route was found' } );
		await dialog( NAME );
		await type( textarea(), 'Elírás' );
		await submit();
		expect( errors() ).toEqual( [ MONEY.saveFail ] );
		expect( reload ).not.toHaveBeenCalled();
	} );

	test( 'a second submit while the first runs is ignored', async () => {
		let release;
		apiFetch.mockReturnValue( new Promise( ( resolve ) => ( release = resolve ) ) );
		await dialog( NAME );
		await type( textarea(), 'Elírás' );
		await submit();
		await submit();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		await act( async () => release( { changed: false, over_limit: [] } ) );
		expect( done ).toHaveBeenCalledWith( { changed: false, over_limit: [] } );
	} );
} );
