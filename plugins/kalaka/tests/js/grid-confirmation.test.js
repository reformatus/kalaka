import { CONFIRMATION_KIND, confirmationOf, manualActions, markOf, refusalOf, validateReason } from '../../src/Admin/app/grid/confirmation';
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
} );

describe( 'a refused unconfirm', () => {
	test( 'a 422 shows the server\'s own text', () => {
		expect( refusalOf( { code: 'kalaka_rule_violation', message: 'A művelet nem hajtható végre.', data: { status: 422 } } ) ).toBe(
			'A művelet nem hajtható végre.'
		);
	} );

	test( 'any other failure shows the general text (null here)', () => {
		expect( refusalOf( { message: 'x', data: { status: 500 } } ) ).toBeNull();
		expect( refusalOf( { data: { status: 422 } } ) ).toBeNull();
		expect( refusalOf( new Error( 'offline' ) ) ).toBeNull();
		expect( refusalOf( null ) ).toBeNull();
	} );
} );
