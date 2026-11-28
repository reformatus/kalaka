/**
 * The event's „Levelek” page's script binds to the PHP markup: a refused switch goes back to where it was (the
 * components' toggle drawn from its wrapper's classes follows it), and the server's text shows on a full-width line
 * under the row; outside a table, on a line under the control's own line.
 */
jest.mock( '../../src/Admin/app/mail/event-page.scss', () => ( {} ) );
jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: jest.fn() } ), { virtual: true } );
jest.mock( '@wordpress/components', () => ( { Button: () => null, Modal: () => null, SelectControl: () => null } ) );
jest.mock( '../../src/Admin/app/grid/api', () => ( { runAbility: jest.fn() } ) );

const { runAbility } = require( '../../src/Admin/app/grid/api' );

const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

window.kalakaMailPage = {
	event: 1,
	rest: '/kalaka-admin/v1',
	bodies: [],
	batches: [],
	texts: { actFail: 'A művelet nem sikerült. Próbáld újra.' },
	urls: {},
};
const { bindSwitch, showError } = require( '../../src/Admin/app/mail/event-page' );

beforeEach( () => {
	document.body.innerHTML = `
		<table><tbody>
			<tr id="row"><th>Jelentkezés rögzítve</th><td>a</td><td>
				<span class="components-form-toggle is-checked">
					<input type="checkbox" class="components-form-toggle__input" data-kalaka-switch="registered" checked>
				</span>
			</td></tr>
			<tr id="next"><th>Más</th><td>b</td><td>c</td></tr>
		</tbody></table>
		<p id="line"><button type="button" id="save">Mentés</button></p>`;
} );

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
} );
