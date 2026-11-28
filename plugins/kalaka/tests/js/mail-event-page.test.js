/**
 * The event's „Levelek” page's script binds to the PHP markup: a refused switch goes back to where it was (the
 * components' toggle drawn from its wrapper's classes follows it), and the server's text shows on a full-width line
 * under the row; outside a table, on a line under the control's own line.
 */
jest.mock( '../../src/Admin/app/mail/event-page.scss', () => ( {} ) );
jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: jest.fn() } ), { virtual: true } );
jest.mock( '@wordpress/components', () => {
	const { createElement: h } = require( 'react' );
	const Button = ( { children, onClick, disabled } ) => h( 'button', { type: 'button', onClick, disabled }, children );
	const Modal = ( { children } ) => h( 'div', null, children );
	const SelectControl = ( { value, options, onChange } ) =>
		h(
			'select',
			{ value, onChange: ( e ) => onChange( e.target.value ) },
			options.map( ( o ) => h( 'option', { key: o.value, value: o.value }, o.label ) )
		);
	return { Button, Modal, SelectControl };
} );
jest.mock( '../../src/Admin/app/grid/api', () => ( { runAbility: jest.fn() } ) );

const { act } = require( 'react' );
const { runAbility } = require( '../../src/Admin/app/grid/api' );

const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

window.kalakaMailPage = {
	event: 1,
	rest: '/kalaka-admin/v1',
	bodies: [
		{ id: 11, label: 'Első' },
		{ id: 12, label: 'Második' },
	],
	batches: [],
	texts: {
		actFail: 'A művelet nem sikerült. Próbáld újra.',
		chooseTitle: 'Sablon kiválasztása',
		choose: 'Kiválasztás',
		clear: 'Törlés',
		cancel: 'Mégsem',
	},
	urls: {},
};
const { bindSwitch, bindChoose, bindLayout, showError } = require( '../../src/Admin/app/mail/event-page' );

// jsdom cannot navigate: a successful act's reload only logs "not implemented".
beforeEach( () => {
	jest.spyOn( console, 'error' ).mockImplementation( () => {} );
	document.body.innerHTML = `
		<table><tbody>
			<tr id="row"><th>Jelentkezés rögzítve</th><td>a</td><td>
				<span class="components-form-toggle is-checked">
					<input type="checkbox" class="components-form-toggle__input" data-kalaka-switch="registered" checked>
				</span>
			</td></tr>
			<tr id="next"><th>Más</th><td>b</td><td>c</td></tr>
		</tbody></table>
		<p id="line"><button type="button" id="save">Mentés</button></p>
		<div class="kalaka-mail-heading" id="heading"><h2>Cím</h2><button type="button" id="new">Új</button></div>
		<button type="button" id="choose" data-kalaka-choose="registered">Sablon</button>
		<textarea id="layout" data-kalaka-layout="1">régi</textarea>
		<button type="button" id="save-layout" data-kalaka-save-layout>Mentés</button>`;
} );

afterEach( () => jest.restoreAllMocks() );

test( 'a refused switch goes back and the text shows on a full-width line under its row', async () => {
	runAbility.mockRejectedValueOnce( { code: 'kalaka_refused', message: 'A szerver szövege.' } );
	const input = document.querySelector( '[data-kalaka-switch]' );
	bindSwitch( input );

	input.checked = false;
	input.dispatchEvent( new Event( 'change' ) );
	expect( input.disabled ).toBe( true );
	expect( input.parentElement.classList.contains( 'is-disabled' ) ).toBe( true );
	await flush();

	expect( runAbility ).toHaveBeenCalledWith( 'kalaka/update-action-mail', {
		event_id: 1,
		mail: 'registered',
		enabled: false,
	} );
	expect( input.checked ).toBe( true );
	expect( input.disabled ).toBe( false );
	expect( input.parentElement.className ).toBe( 'components-form-toggle is-checked' );
	const line = document.getElementById( 'row' ).nextElementSibling;
	expect( line.classList.contains( 'kalaka-mail-error' ) ).toBe( true );
	expect( line.querySelector( 'td' ).colSpan ).toBe( 3 );
	expect( line.textContent ).toBe( 'A szerver szövege.' );
	expect( line.querySelector( '[role="alert"]' ) ).not.toBeNull();
} );

test( 'a second refusal replaces the first line, and a control outside a table gets a line under its own', () => {
	const input = document.querySelector( '[data-kalaka-switch]' );
	showError( input, 'Első.' );
	showError( input, 'Második.' );
	expect( document.querySelectorAll( '.kalaka-mail-error' ) ).toHaveLength( 1 );
	expect( document.getElementById( 'row' ).nextElementSibling.textContent ).toBe( 'Második.' );

	showError( document.getElementById( 'save' ), 'Harmadik.' );
	const after = document.getElementById( 'line' ).nextElementSibling;
	expect( after.tagName ).toBe( 'DIV' );
	expect( after.textContent ).toBe( 'Harmadik.' );

	showError( document.getElementById( 'new' ), 'Negyedik.' );
	expect( document.getElementById( 'heading' ).nextElementSibling.textContent ).toBe( 'Negyedik.' );
} );

test( 'the choose dialog follows the select: the submit sends the chosen template', async () => {
	runAbility.mockResolvedValueOnce( {} );
	const trigger = document.getElementById( 'choose' );
	bindChoose( trigger );
	await act( async () => {
		trigger.click();
	} );

	const select = document.querySelector( 'select' );
	expect( select.value ).toBe( '11' );
	await act( async () => {
		const setter = Object.getOwnPropertyDescriptor( window.HTMLSelectElement.prototype, 'value' ).set;
		setter.call( select, '12' );
		select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );
	expect( document.querySelector( 'select' ).value ).toBe( '12' );

	const submit = [ ...document.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent === 'Kiválasztás' );
	await act( async () => {
		submit.click();
	} );
	expect( runAbility ).toHaveBeenCalledWith( 'kalaka/update-action-mail', {
		event_id: 1,
		mail: 'registered',
		body_id: 12,
	} );
} );

describe( 'the levélkeret leave warning', () => {
	const leaving = () => {
		const event = new Event( 'beforeunload', { cancelable: true } );
		window.dispatchEvent( event );
		return event.defaultPrevented;
	};

	test( 'warns while the text differs, stops once a save succeeded and stays after a failed one', async () => {
		const area = document.getElementById( 'layout' );
		bindLayout( area );
		expect( leaving() ).toBe( false );

		area.value = 'új';
		expect( leaving() ).toBe( true );

		runAbility.mockRejectedValueOnce( { code: 'kalaka_refused', message: 'Nem.' } );
		document.getElementById( 'save-layout' ).click();
		await flush();
		expect( leaving() ).toBe( true );

		runAbility.mockResolvedValueOnce( {} );
		document.getElementById( 'save-layout' ).click();
		await flush();
		expect( runAbility ).toHaveBeenLastCalledWith( 'kalaka/set-event-mail-layout', { event_id: '1', html: 'új' } );
		expect( leaving() ).toBe( false );
	} );
} );
