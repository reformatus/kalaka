import { CONFIRMATION_KIND, confirmationOf, markOf, validateReason } from '../../src/Admin/app/grid/confirmation';
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
