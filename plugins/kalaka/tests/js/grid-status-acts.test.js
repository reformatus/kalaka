/**
 * The status acts of the detail panel (S39 on #247): the acts row from `can` only, each dialog (the ability and
 * input it runs, the reason rule, the preview's warnings, capacity lines and money section), a typed refusal
 * shown with the server's text, the generic failure for the rest, and what is handed to `onDone`.
 */
import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';
import StatusActDialog from '../../src/Admin/app/grid/StatusActDialog';
import StatusActs from '../../src/Admin/app/grid/StatusActs';
import { abilityOf, actsFor, failureOf, inputOf, moneyRowsOf, retentionLineOf, seatLinesOf, showsRefundLine, staleAfter, warningsOf } from '../../src/Admin/app/grid/statusActs';
import { CONFIRM, MONEY, STATUS_ACTS } from '../../src/Admin/app/grid/texts';

jest.mock( '@wordpress/components', () => {
	const { createElement: h } = require( 'react' );
	const TextareaControl = ( { label, value, onChange, help } ) =>
		h( 'label', null, label, h( 'textarea', { 'aria-label': label, value, onChange: ( e ) => onChange( e.target.value ) } ), help );
	const Button = ( { children, ...rest } ) => {
		delete rest.isBusy;
		return h( 'button', rest, children );
	};
	const Modal = ( { title, children, aria } ) =>
		h( 'div', { role: 'dialog', 'aria-describedby': aria && aria.describedby }, h( 'h1', null, title ), children );
	const Notice = ( { children, status } ) => h( 'div', { 'data-notice': status }, children );
	return { Button, Modal, Notice, TextareaControl, SnackbarList: () => null };
} );

jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: jest.fn() } ), { virtual: true } );

window.IS_REACT_ACT_ENVIRONMENT = true;

let host;
let root;
let done;
let reload;
const config = { rest: '', event: 7 };
const FULL_TEXT = 'Ez a lehetőség már betelt. Ha ide sorolod a jelentkezőt, a létszám túllépi a keretet.';
const OVER = {
	changed: true,
	status: 'registered',
	over_capacity: true,
	seats: [ { pool: 'event', label: 'Nyári tábor', field: null, outcome: 'over', taken: 6, taken_after: 7, limit: 6 } ],
	warnings: [ FULL_TEXT ],
};
const HELD = {
	changed: true,
	status: 'registered',
	over_capacity: false,
	seats: [ { pool: 'event', label: 'Nyári tábor', field: null, outcome: 'held', taken: 4, taken_after: 5, limit: 6 } ],
	warnings: [],
};
const MONEY_LEFT = {
	changed: true,
	status: 'withdrawn',
	over_capacity: false,
	seats: [],
	warnings: [],
	money: [ { payer: 'participant', paid: '60000.00000000', balance_now: '10000.00000000', balance_after: '-5000.00000000' } ],
};
const DETAIL_MONEY = { currency: 'HUF', payers: [ { payer: 'participant', label: 'Résztvevő' } ] };

async function render( act_, preview = HELD, money = DETAIL_MONEY ) {
	apiFetch.mockImplementation( ( request ) => Promise.resolve( request.data.input.preview ? preview : HELD ) );
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
	done = jest.fn().mockResolvedValue( undefined );
	reload = jest.fn();
	await act( async () => {
		root.render( createElement( StatusActDialog, { config, code: '92F7', act: act_, money, onClose: () => {}, onDone: done, reload } ) );
	} );
}
const textarea = () => host.querySelector( 'textarea' );
const notices = ( status ) => Array.from( host.querySelectorAll( `[data-notice="${ status }"]` ) ).map( ( n ) => n.textContent );
async function write( value ) {
	const setter = Object.getOwnPropertyDescriptor( window.HTMLTextAreaElement.prototype, 'value' ).set;
	await act( async () => {
		setter.call( textarea(), value );
		textarea().dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
}
async function submit() {
	await act( async () => {
		host.querySelector( 'form' ).dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );
	} );
}
const writes = () => apiFetch.mock.calls.map( ( call ) => call[ 0 ] ).filter( ( request ) => ! request.data.input.preview );

beforeEach( () => apiFetch.mockReset() );
afterEach( async () => {
	if ( root ) {
		await act( async () => root.unmount() );
		root = null;
		host.remove();
	}
} );

describe( 'the acts row', () => {
	test( 'shows exactly the acts whose can flag is true, in the fixed order', () => {
		expect( actsFor( { restore: true, admit: true, withdraw: false, remove: true } ) ).toEqual( [ 'admit', 'remove', 'restore' ] );
		expect( actsFor( {} ) ).toEqual( [] );
		expect( actsFor( undefined ) ).toEqual( [] );
	} );

	test( 'renders one button per act and none when stale', async () => {
		host = document.createElement( 'div' );
		document.body.appendChild( host );
		root = createRoot( host );
		const can = { withdraw: true, remove: true };
		await act( async () => root.render( createElement( StatusActs, { config, code: 'x', can, stale: false, reload: () => {}, onSaved: () => {} } ) ) );
		expect( Array.from( host.querySelectorAll( 'button' ) ).map( ( b ) => b.textContent ) ).toEqual( [ STATUS_ACTS.withdraw, STATUS_ACTS.remove ] );
		await act( async () => root.render( createElement( StatusActs, { config, code: 'x', can, stale: true, reload: () => {}, onSaved: () => {} } ) ) );
		expect( host.querySelectorAll( 'button' ) ).toHaveLength( 0 );
	} );
} );

describe( 'the call', () => {
	test( 'names the ability and the input', () => {
		expect( abilityOf( 'admit' ) ).toBe( 'kalaka/admit-registration' );
		expect( inputOf( 7, 'A1', '' ) ).toEqual( { event_id: 7, code: 'A1' } );
		// A preview carries no reason, even when one is given: the server takes none on a preview.
		expect( inputOf( 7, 'A1', 'ok', true ) ).toEqual( { event_id: 7, code: 'A1', preview: true } );
		expect( inputOf( 7, 'A1', 'ok' ) ).toEqual( { event_id: 7, code: 'A1', reason: 'ok' } );
	} );
} );

describe( 'each dialog', () => {
	test.each( [ 'admit', 'withdraw', 'remove', 'restore' ] )( '%s runs its ability with the trimmed reason and hands the act on', async ( name ) => {
		await render( name );
		await write( '  Telefonon kérte  ' );
		await submit();
		expect( writes() ).toHaveLength( 1 );
		expect( writes()[ 0 ].path ).toBe( `/wp-abilities/v1/abilities/kalaka/${ name }-registration/run` );
		expect( writes()[ 0 ].data.input ).toEqual( { event_id: 7, code: '92F7', reason: 'Telefonon kérte' } );
		expect( done ).toHaveBeenCalledWith( name );
	} );

	test( 'withdraw, admit and restore send without a reason; remove needs one', async () => {
		await render( 'withdraw' );
		await submit();
		expect( writes()[ 0 ].data.input ).toEqual( { event_id: 7, code: '92F7' } );
		await act( async () => root.unmount() );
		host.remove();
		apiFetch.mockReset();
		await render( 'remove' );
		await submit();
		expect( writes() ).toHaveLength( 0 );
		expect( host.textContent ).toContain( CONFIRM.reasonEmpty );
		await write( 'a'.repeat( 501 ) );
		await submit();
		expect( writes() ).toHaveLength( 0 );
		expect( host.textContent ).toContain( CONFIRM.reasonTooLong );
	} );

	test( 'every act asks the preview as the dialog opens, without a reason, remove included', async () => {
		for ( const name of [ 'admit', 'withdraw', 'remove', 'restore' ] ) {
			apiFetch.mockReset();
			await render( name );
			expect( apiFetch ).toHaveBeenCalledTimes( 1 );
			expect( apiFetch.mock.calls[ 0 ][ 0 ].data.input ).toEqual( { event_id: 7, code: '92F7', preview: true } );
			await act( async () => root.unmount() );
			host.remove();
		}
	} );

	test( 'remove shows its money from the start and leaving the reason field asks nothing more', async () => {
		await render( 'remove', MONEY_LEFT );
		expect( host.textContent ).toContain( STATUS_ACTS.moneyHeading );
		await write( 'Dupla jelentkezés' );
		await act( async () => {
			textarea().dispatchEvent( new Event( 'focusout', { bubbles: true } ) );
		} );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'the dialog is described by its text and capacity and by the money section; the warning is in neither', async () => {
		await render( 'withdraw', { ...MONEY_LEFT, seats: OVER.seats, warnings: [ FULL_TEXT ], retention: 'everything_paid' } );
		const ids = host.querySelector( '[role="dialog"]' ).getAttribute( 'aria-describedby' ).split( ' ' );
		expect( ids ).toHaveLength( 2 );
		const [ text, money ] = ids.map( ( id ) => host.querySelector( `#${ id }` ) );
		expect( text.textContent ).toContain( STATUS_ACTS.withdrawBody );
		expect( text.querySelector( '.kg-act-capacity' ) ).not.toBeNull();
		expect( money.querySelector( '.kg-act-money' ) ).not.toBeNull();
		expect( text.textContent + money.textContent ).not.toContain( FULL_TEXT );
		expect( host.querySelector( 'textarea' ).closest( '#kg-act-description, #kg-act-money' ) ).toBeNull();
	} );

	test( 'once the preview has answered, a polite live region says the capacity, each payer\'s figures and the sentences, not the warning', async () => {
		await render( 'withdraw', { ...MONEY_LEFT, seats: OVER.seats, warnings: [ FULL_TEXT ], retention: 'everything_paid' } );
		const region = host.querySelector( '[role="status"]' );
		expect( region.getAttribute( 'aria-live' ) ).toBe( 'polite' );
		expect( region.textContent ).toBe(
			'Nyári tábor – foglalt helyek mentés után: 7 / 6. Pénzügyi hatás. ' +
				'Fizető: Résztvevő, Befizetett 60\u00a0000\u00a0Ft, Jelenlegi tartozás 10\u00a0000\u00a0Ft, Tartozás mentés után −5\u00a0000\u00a0Ft (túlfizetés). ' +
				'Az esemény a befizetett összeget teljes egészében megtartja. Nincs automatikus visszafizetés.'
		);
		expect( region.textContent ).not.toContain( FULL_TEXT );
		await act( async () => root.unmount() );
		host.remove();
		await render( 'admit', { ...HELD, seats: [] } );
		expect( host.querySelector( '[role="status"]' ).textContent ).toBe( '' );
	} );

	test( 'the warning is the preview\'s own text, one notice per warning, none when it has none', async () => {
		await render( 'admit', OVER );
		expect( notices( 'warning' ) ).toEqual( [ FULL_TEXT ] );
		await act( async () => root.unmount() );
		host.remove();
		await render( 'restore', HELD );
		expect( notices( 'warning' ) ).toEqual( [] );
		expect( warningsOf( null ) ).toEqual( [] );
		expect( warningsOf( { warnings: [ 'a', '', 'b' ] } ) ).toEqual( [ 'a', 'b' ] );
	} );

	test( 'a seat with a limit gets its label and taken_after / limit; an unlimited seat has no line', async () => {
		await render( 'admit', OVER );
		// The count after the act, from the server's `taken_after`: 6 / 6 becomes 7 / 6.
		expect( host.querySelector( '.kg-act-capacity' ).textContent ).toBe( 'Nyári tábor – foglalt helyek mentés után: 7 / 6' );
		expect( seatLinesOf( { seats: [ { pool: 'x', label: 'Sáv', field: 'f', outcome: 'released', taken: 5, taken_after: 5, limit: 6 } ] } )[ 0 ].line ).toBe( 'Sáv – foglalt helyek mentés után: 5 / 6' );
		expect( seatLinesOf( { seats: [ { pool: 'x', label: 'Sáv', field: 'f', outcome: 'held', taken: 1, taken_after: 2, limit: null } ] } ) ).toEqual( [] );
		expect( seatLinesOf( null ) ).toEqual( [] );
		// No label: no dash starts the line (T723).
		expect( seatLinesOf( { seats: [ { pool: 'x', label: '', field: 'f', outcome: 'released', taken: 5, taken_after: 5, limit: 6 } ] } )[ 0 ].line ).toBe( 'Foglalt helyek mentés után: 5 / 6' );
	} );

	test( 'the money section lists each payer from the preview; the retention sentence follows only the preview\'s `retention`', async () => {
		await render( 'withdraw', { ...MONEY_LEFT, retention: 'everything_paid' } );
		const section = host.querySelector( '.kg-act-money' );
		expect( section.querySelector( 'h3' ).textContent ).toBe( STATUS_ACTS.moneyHeading );
		expect( Array.from( section.querySelectorAll( 'thead th' ) ).map( ( th ) => th.textContent ) ).toEqual( [ MONEY.payer, MONEY.paid, MONEY.balanceNow, MONEY.balanceAfter ] );
		// The payer is the row's header, as in the panel's money tab; the figures carry `is-num`.
		expect( section.querySelector( 'tbody th' ).getAttribute( 'scope' ) ).toBe( 'row' );
		expect( section.querySelector( 'tbody th' ).textContent ).toBe( 'Résztvevő' );
		expect( section.querySelectorAll( 'tbody td.is-num' ) ).toHaveLength( 3 );
		expect( Array.from( section.querySelectorAll( 'tbody td' ) ).map( ( td ) => td.textContent ) ).toEqual( [
			'60\u00a0000\u00a0Ft',
			'10\u00a0000\u00a0Ft',
			'−5\u00a0000\u00a0Ft (túlfizetés)',
		] );
		expect( section.textContent ).toContain( STATUS_ACTS.noAutomaticRefund );
		expect( section.textContent ).toContain( STATUS_ACTS.retentionAll );
		expect( retentionLineOf( { retention: 'nothing' } ) ).toBe( STATUS_ACTS.retentionNone );
		expect( retentionLineOf( { retention: null } ) ).toBe( '' );
		expect( retentionLineOf( {} ) ).toBe( '' );
		expect( retentionLineOf( null ) ).toBe( '' );
	} );

	test( 'the no-refund line shows for withdraw and remove only, not for restore', async () => {
		expect( [ 'admit', 'withdraw', 'remove', 'restore' ].map( showsRefundLine ) ).toEqual( [ false, true, true, false ] );
		await render( 'restore', MONEY_LEFT );
		expect( host.querySelector( '.kg-act-money' ).textContent ).not.toContain( STATUS_ACTS.noAutomaticRefund );
		expect( host.querySelector( '.kg-act-money' ).textContent ).not.toContain( STATUS_ACTS.retentionAll );
	} );

	test( 'no money section for admit, without money in the preview, or without a payer', async () => {
		await render( 'admit', HELD );
		expect( host.querySelector( '.kg-act-money' ) ).toBeNull();
		await act( async () => root.unmount() );
		host.remove();
		// A holder without money view (`money_hidden`) and a fee that cannot be priced both send `money: null`: no section.
		await render( 'withdraw', { ...MONEY_LEFT, money: null, money_hidden: true, retention: null } );
		expect( host.querySelector( '.kg-act-money' ) ).toBeNull();
		await act( async () => root.unmount() );
		host.remove();
		await render( 'withdraw', { ...MONEY_LEFT, money: null, money_hidden: false, retention: 'everything_paid' } );
		expect( host.querySelector( '.kg-act-money' ) ).toBeNull();
		expect( moneyRowsOf( { money: [] }, DETAIL_MONEY ) ).toBeNull();
		await act( async () => root.unmount() );
		host.remove();
		// An empty `money` list hides the whole section, the retention sentence with it (N717).
		await render( 'withdraw', { ...MONEY_LEFT, money: [], retention: 'everything_paid' } );
		expect( host.querySelector( '.kg-act-money' ) ).toBeNull();
		expect( host.textContent ).not.toContain( STATUS_ACTS.retentionAll );
		expect( moneyRowsOf( null, DETAIL_MONEY ) ).toBeNull();
	} );

	test( 'a payer the read does not name is shown by its key; the currency is the read\'s', () => {
		const rows = moneyRowsOf( MONEY_LEFT, { currency: 'EUR', payers: [] } );
		expect( rows[ 0 ].payer ).toBe( 'participant' );
		expect( rows[ 0 ].paid ).toBe( '60\u00a0000\u00a0EUR' );
	} );

	test( 'a restore of a leaver shows the reversal as the server computed it', async () => {
		await render( 'restore', { ...HELD, money: [ { payer: 'participant', paid: '20000.00000000', balance_now: '-20000.00000000', balance_after: '40000.00000000' } ] } );
		expect( Array.from( host.querySelectorAll( '.kg-act-money tbody td' ) ).map( ( td ) => td.textContent ).slice( 1 ) ).toEqual( [ '−20\u00a0000\u00a0Ft (túlfizetés)', '40\u00a0000\u00a0Ft' ] );
	} );

	test( 'a failed preview shows nothing', async () => {
		apiFetch.mockReset();
		apiFetch.mockImplementation( () => Promise.reject( { code: 'kalaka_not_found', message: 'x' } ) );
		host = document.createElement( 'div' );
		document.body.appendChild( host );
		root = createRoot( host );
		await act( async () => {
			root.render( createElement( StatusActDialog, { config, code: '92F7', act: 'admit', money: DETAIL_MONEY, onClose: () => {}, onDone: jest.fn(), reload: jest.fn() } ) );
		} );
		expect( notices( 'warning' ) ).toEqual( [] );
		expect( host.querySelector( '.kg-act-money' ) ).toBeNull();
	} );
} );

describe( 'a refusal', () => {
	test( 'a typed refusal shows the server text and the panel re-reads', async () => {
		await render( 'withdraw' );
		apiFetch.mockImplementation( () => Promise.reject( { code: 'kalaka_status_act_refused', message: 'A művelet nem hajtható végre.' } ) );
		await submit();
		expect( notices( 'error' ) ).toEqual( [ 'A művelet nem hajtható végre.' ] );
		expect( reload ).toHaveBeenCalled();
		expect( done ).not.toHaveBeenCalled();
	} );

	test( 'anything else is the generic failure, English or raw never shown', async () => {
		await render( 'withdraw' );
		apiFetch.mockImplementation( () => Promise.reject( { code: 'rest_forbidden', message: 'Sorry, you are not allowed.' } ) );
		await submit();
		expect( notices( 'error' ) ).toEqual( [ MONEY.saveFail ] );
		expect( reload ).not.toHaveBeenCalled();
		expect( failureOf( undefined ) ).toBe( MONEY.saveFail );
		expect( failureOf( { code: 'kalaka_not_found', message: '' } ) ).toBe( MONEY.saveFail );
		expect( staleAfter( { code: 'kalaka_version_conflict' } ) ).toBe( true );
		expect( staleAfter( { code: 'kalaka_permission_denied' } ) ).toBe( false );
	} );

	test( 'a second submit while it runs sends nothing more', async () => {
		await render( 'withdraw' );
		let release;
		apiFetch.mockImplementation( () => new Promise( ( resolve ) => ( release = resolve ) ) );
		await submit();
		await submit();
		expect( writes() ).toHaveLength( 1 );
		await act( async () => release( HELD ) );
	} );
} );
