import * as confirmation from '../../src/Admin/app/grid/confirmation';
import {
	CONFIRMATION_KIND,
	confirmationOf,
	manualActions,
	markOf,
	REASON_MAX,
	snackbarAfter,
	validateReason,
	warningOf,
} from '../../src/Admin/app/grid/confirmation';
import { formatMoment } from '../../src/Admin/app/grid/money';
import { CONFIRM } from '../../src/Admin/app/grid/texts';

const confirmed = {
	kind: 'confirmation',
	label: 'Visszaigazolás',
	state: 'confirmed',
	state_label: 'Visszaigazolva',
	effective_at: '2026-11-19T10:00:00Z',
};

describe( 'the confirmation decision of a read', () => {
	test( 'is found by its kind among the decisions', () => {
		const other = { kind: 'payment_hold', state: 'held', state_label: 'Zárolva' };
		expect( CONFIRMATION_KIND ).toBe( 'confirmation' );
		expect( confirmationOf( { decisions: [ other, confirmed ] } ) ).toBe( confirmed );
	} );

	test( 'is null when the read has none, or no decisions at all', () => {
		expect( confirmationOf( { decisions: [] } ) ).toBeNull();
		expect( confirmationOf( { decisions: [ { kind: 'payment_hold' } ] } ) ).toBeNull();
		expect( confirmationOf( {} ) ).toBeNull();
		expect( confirmationOf( undefined ) ).toBeNull();
	} );

	test( 'a state has its icon, an unknown one the grid\'s marker', () => {
		expect( markOf( 'confirmed' ) ).toEqual( [ 'dashicons-yes-alt', 'kg-ok' ] );
		expect( markOf( 'unconfirmed' ) ).toEqual( [ 'dashicons-marker', '' ] );
		expect( markOf( 'whatever' ) ).toEqual( [ 'dashicons-marker', '' ] );
	} );
} );

describe( 'the reason', () => {
	test( 'is required: empty and blank are refused with the message', () => {
		expect( validateReason( '' ) ).toBe( CONFIRM.reasonEmpty );
		expect( validateReason( '   \n ' ) ).toBe( CONFIRM.reasonEmpty );
	} );

	test( 'any text is accepted', () => {
		expect( validateReason( 'Telefonon egyeztettünk' ) ).toBe( '' );
	} );

	test( 'is at most 500 characters, counted on the trimmed text and by character', () => {
		expect( REASON_MAX ).toBe( 500 );
		expect( validateReason( 'a'.repeat( 500 ) ) ).toBe( '' );
		expect( validateReason( 'a'.repeat( 501 ) ) ).toBe( CONFIRM.reasonTooLong );
		// What is sent is trimmed: the spaces around do not count.
		expect( validateReason( `  ${ 'a'.repeat( 500 ) }  ` ) ).toBe( '' );
		// An astral character is one character (an emoji is two UTF-16 units).
		expect( validateReason( '😀'.repeat( 500 ) ) ).toBe( '' );
		expect( validateReason( '😀'.repeat( 501 ) ) ).toBe( CONFIRM.reasonTooLong );
	} );

	test( 'both messages are the dialog\'s', () => {
		expect( CONFIRM.reasonTooLong ).toBe( 'Az indoklás legfeljebb 500 karakter lehet.' );
	} );
} );

describe( 'the moment of the flag', () => {
	test( 'is the day and the time in the site time zone', () => {
		expect( formatMoment( '2026-11-19T13:05:00Z', 'Europe/Budapest' ) ).toBe( '2026. 11. 19. 14:05' );
		expect( formatMoment( '2026-07-01T22:30:00Z', 'Europe/Budapest' ) ).toBe( '2026. 07. 02. 00:30' );
		expect( formatMoment( '2026-11-19T13:05:00Z', '+01:00' ) ).toBe( '2026. 11. 19. 14:05' );
		expect( formatMoment( '2026-11-19T13:05:00Z', 'UTC' ) ).toBe( '2026. 11. 19. 13:05' );
	} );

	test( 'is empty for no instant', () => {
		expect( formatMoment( null, 'UTC' ) ).toBe( '' );
	} );
} );

describe( 'the buttons of the manual confirmation block', () => {
	const flag = { set_at: '2026-11-19T10:00:00Z', reason: 'Telefonon', set_by: 'Teszt' };

	test( 'none without the actions', () => {
		expect( manualActions( null, { confirm_manually: false, unconfirm: false } ) ).toEqual( [] );
		expect( manualActions( undefined, {} ) ).toEqual( [] );
		expect( manualActions( flag, undefined ) ).toEqual( [] );
	} );

	test( 'setting is offered with can.confirm_manually while the flag is not set', () => {
		expect( manualActions( null, { confirm_manually: true } ) ).toEqual( [ 'confirm' ] );
		expect( manualActions( flag, { confirm_manually: true } ) ).toEqual( [] );
	} );

	test( 'a read without the flag (no money view) still offers setting, which is idempotent', () => {
		expect( manualActions( undefined, { confirm_manually: true } ) ).toEqual( [ 'confirm' ] );
	} );

	test( 'the one unconfirm is offered only with can.unconfirm, whatever the flag says', () => {
		expect( manualActions( null, { confirm_manually: true, unconfirm: true } ) ).toEqual( [ 'confirm', 'unconfirm' ] );
		expect( manualActions( flag, { confirm_manually: true, unconfirm: true } ) ).toEqual( [ 'unconfirm' ] );
		expect( manualActions( undefined, { unconfirm: true } ) ).toEqual( [ 'unconfirm' ] );
		expect( manualActions( flag, { confirm_manually: true, unconfirm: false } ) ).toEqual( [] );
	} );

	test( 'can.unconfirm decides alone: it does not matter why the registration is confirmed', () => {
		// The pure logic takes only the flag and `can`: no status, no confirmation cause, no money.
		expect( manualActions.length ).toBe( 2 );
		const can = { unconfirm: true, status: 'waitlisted', cause: 'payments', threshold: 'below', view_money: false };
		expect( manualActions( null, can ) ).toEqual( [ 'unconfirm' ] );
		expect( manualActions( flag, { ...can, cause: 'nothing_due' } ) ).toEqual( [ 'unconfirm' ] );
		expect( manualActions( flag, { ...can, cause: 'manual' } ) ).toEqual( [ 'unconfirm' ] );
		expect( manualActions( flag, { ...can, unconfirm: false, cause: 'manual' } ) ).toEqual( [] );
	} );

	test( 'the set button follows can.confirm_manually only, whatever else the read says', () => {
		expect( manualActions( null, { confirm_manually: true, status: 'withdrawn', unconfirm: false } ) ).toEqual( [ 'confirm' ] );
		expect( manualActions( null, { confirm_manually: false, status: 'registered' } ) ).toEqual( [] );
	} );
} );

describe( 'the warning before the unconfirm', () => {
	const text = 'A jelentkezés a visszavonás után azonnal újra visszaigazolt lesz.';

	test( 'is the server\'s text, shown when it is not null and can.unconfirm is true', () => {
		expect( warningOf( { unconfirm_warning: text }, { unconfirm: true } ) ).toBe( text );
	} );

	test( 'is absent when the server says null, or the read does not carry it', () => {
		expect( warningOf( { unconfirm_warning: null }, { unconfirm: true } ) ).toBeNull();
		expect( warningOf( { unconfirm_warning: '' }, { unconfirm: true } ) ).toBeNull();
		expect( warningOf( {}, { unconfirm: true } ) ).toBeNull();
		expect( warningOf( undefined, { unconfirm: true } ) ).toBeNull();
	} );

	test( 'is absent without can.unconfirm: the panel never shows a warning for an action it does not offer', () => {
		expect( warningOf( { unconfirm_warning: text }, { unconfirm: false } ) ).toBeNull();
		expect( warningOf( { unconfirm_warning: text }, {} ) ).toBeNull();
		expect( warningOf( { unconfirm_warning: text }, undefined ) ).toBeNull();
	} );
} );

describe( 'the snackbar after a save', () => {
	const detail = ( state ) => ( {
		registration: { decisions: [ { kind: 'confirmation', state, state_label: state } ] },
	} );

	test( 'a set says it is saved', () => {
		expect( snackbarAfter( false, { changed: true }, detail( 'confirmed' ) ) ).toBe( CONFIRM.saved );
	} );

	test( 'an unconfirm that ends not confirmed says it was revoked', () => {
		expect( snackbarAfter( true, { changed: true }, detail( 'unconfirmed' ) ) ).toBe( CONFIRM.unconfirmed );
		expect( CONFIRM.unconfirmed ).toBe( 'Visszaigazolás visszavonva.' );
	} );

	test( 'an unconfirm the rules re-confirmed at once says so: the re-read ends confirmed', () => {
		expect( snackbarAfter( true, { changed: true }, detail( 'confirmed' ) ) ).toBe( CONFIRM.unconfirmedReconfirmed );
		expect( CONFIRM.unconfirmedReconfirmed ).toBe( 'Visszaigazolás visszavonva; a szabályok szerint újra visszaigazolva.' );
	} );

	describe( 'on a view without the confirmation column (the re-read carries no decision)', () => {
		const bare = ( can ) => ( { registration: { decisions: [] }, can } );

		test( 'can.unconfirm true means it is confirmed again: the re-confirm snackbar, not "revoked"', () => {
			expect( snackbarAfter( true, { changed: true }, bare( { unconfirm: true } ) ) ).toBe( CONFIRM.unconfirmedReconfirmed );
		} );

		test( 'can.unconfirm false means it is not confirmed: revoked', () => {
			expect( snackbarAfter( true, { changed: true }, bare( { unconfirm: false } ) ) ).toBe( CONFIRM.unconfirmed );
		} );

		test( 'a read that carries neither the decision nor can.unconfirm cannot tell: no snackbar', () => {
			expect( snackbarAfter( true, { changed: true }, bare( {} ) ) ).toBeNull();
			expect( snackbarAfter( true, { changed: true }, bare( undefined ) ) ).toBeNull();
			expect( snackbarAfter( true, { changed: true }, { registration: {} } ) ).toBeNull();
		} );

		test( 'the decision, where the read carries it, wins over can.unconfirm', () => {
			const read = ( state, unconfirm ) => ( { ...detail( state ), can: { unconfirm } } );
			expect( snackbarAfter( true, { changed: true }, read( 'unconfirmed', true ) ) ).toBe( CONFIRM.unconfirmed );
			expect( snackbarAfter( true, { changed: true }, read( 'confirmed', false ) ) ).toBe( CONFIRM.unconfirmedReconfirmed );
		} );

		test( 'a set says it is saved whatever the read carries', () => {
			expect( snackbarAfter( false, { changed: true }, bare( {} ) ) ).toBe( CONFIRM.saved );
		} );
	} );

	test( 'changed: false writes nothing: no snackbar, for either act', () => {
		expect( snackbarAfter( true, { changed: false }, detail( 'unconfirmed' ) ) ).toBeNull();
		expect( snackbarAfter( true, { changed: false }, detail( 'confirmed' ) ) ).toBeNull();
		expect( snackbarAfter( false, { changed: false }, detail( 'confirmed' ) ) ).toBeNull();
	} );

	test( 'a failed re-read claims nothing: no snackbar, the panel\'s load error shows', () => {
		expect( snackbarAfter( true, { changed: true }, null ) ).toBeNull();
		expect( snackbarAfter( false, { changed: true }, null ) ).toBeNull();
	} );
} );

describe( 'the dead 422 path', () => {
	test( 'is gone: the unconfirm has no refusal, so nothing reads a refusal text', () => {
		expect( confirmation.refusalOf ).toBeUndefined();
	} );
} );
