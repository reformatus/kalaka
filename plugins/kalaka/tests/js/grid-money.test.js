import {
	amountString,
	balanceAfter,
	balanceText,
	formatAbsolute,
	formatDay,
	formatMinor,
	formatMoney,
	methodsFor,
	parseAmount,
	toMinor,
	today,
	validateEntry,
} from '../../src/Admin/app/grid/money';
import { MONEY } from '../../src/Admin/app/grid/texts';

const NBSP = ' ';

describe( 'amounts in fillér', () => {
	test( 'the read\'s eight decimals become whole fillér, signed', () => {
		expect( toMinor( '60000.00000000' ) ).toBe( 6000000 );
		expect( toMinor( '-35000.00000000' ) ).toBe( -3500000 );
		expect( toMinor( '1000.50000000' ) ).toBe( 100050 );
		expect( toMinor( '0.00000000' ) ).toBe( 0 );
		expect( toMinor( '12' ) ).toBe( 1200 );
	} );

	test( 'a third decimal rounds, and a non-number is null', () => {
		expect( toMinor( '0.005' ) ).toBe( 0 );
		expect( toMinor( '-0.005' ) ).toBe( 0 );
		expect( Object.is( toMinor( '-0.005' ), 0 ) ).toBe( true );
		expect( toMinor( '1.009' ) ).toBe( 100 );
		expect( toMinor( '-1.009' ) ).toBe( -100 );
		expect( toMinor( '1.99999999' ) ).toBe( 199 );
		expect( toMinor( '9999999999999.99999999' ) ).toBe( 999999999999999 );
		expect( toMinor( '0.004' ) ).toBe( 0 );
		expect( toMinor( 'abc' ) ).toBeNull();
		expect( toMinor( '' ) ).toBeNull();
	} );
} );

describe( 'money as shown', () => {
	test( '„30 000 Ft” with no-break spaces, also on four digits', () => {
		expect( formatMoney( '30000.00000000' ) ).toBe( `30${ NBSP }000${ NBSP }Ft` );
		expect( formatMoney( '1000.00000000' ) ).toBe( `1${ NBSP }000${ NBSP }Ft` );
		expect( formatMoney( '1822500.00000000' ) ).toBe( `1${ NBSP }822${ NBSP }500${ NBSP }Ft` );
		expect( formatMoney( '0.00000000' ) ).toBe( `0${ NBSP }Ft` );
	} );

	test( 'fillér show with a decimal comma, and a negative with a real minus', () => {
		expect( formatMoney( '1000.50000000' ) ).toBe( `1${ NBSP }000,50${ NBSP }Ft` );
		expect( formatMoney( '1000.05000000' ) ).toBe( `1${ NBSP }000,05${ NBSP }Ft` );
		expect( formatMinor( -500000 ) ).toBe( `−5${ NBSP }000${ NBSP }Ft` );
	} );

	test( 'an entry\'s amount is absolute: the type word gives the direction', () => {
		expect( formatAbsolute( '-35000.00000000' ) ).toBe( `35${ NBSP }000${ NBSP }Ft` );
		expect( formatAbsolute( '1000.00000000' ) ).toBe( `1${ NBSP }000${ NBSP }Ft` );
	} );

	test( 'the balance reads as words', () => {
		expect( balanceText( 3000000 ) ).toBe( `30${ NBSP }000${ NBSP }Ft hátralék` );
		expect( balanceText( -500000 ) ).toBe( `5${ NBSP }000${ NBSP }Ft túlfizetés` );
		expect( balanceText( toMinor( '0.005' ) ) ).toBe( `0${ NBSP }Ft` );
		expect( balanceText( 3000000, 'EUR' ) ).toBe( `30${ NBSP }000${ NBSP }EUR hátralék` );
		expect( formatMinor( 100050, 'EUR' ) ).toBe( `1${ NBSP }000,50${ NBSP }EUR` );
		expect( formatMinor( 100050, 'HUF' ) ).toBe( `1${ NBSP }000,50${ NBSP }Ft` );
		expect( balanceText( 0 ) ).toBe( `0${ NBSP }Ft` );
	} );
} );

describe( 'the typed amount', () => {
	test( 'spaces, a decimal comma and a dot are taken', () => {
		expect( parseAmount( '30 000' ) ).toBe( 3000000 );
		expect( parseAmount( `30${ NBSP }000` ) ).toBe( 3000000 );
		expect( parseAmount( '1000,5' ) ).toBe( 100050 );
		expect( parseAmount( '1000.50' ) ).toBe( 100050 );
		expect( parseAmount( '0' ) ).toBe( 0 );
	} );

	test( 'a thousands dot, a third decimal, a sign or text is not an amount', () => {
		expect( parseAmount( '10.000' ) ).toBeNull();
		expect( parseAmount( '1,005' ) ).toBeNull();
		expect( parseAmount( '-5' ) ).toBeNull();
		expect( parseAmount( '12 Ft' ) ).toBeNull();
		expect( parseAmount( '' ) ).toBeNull();
	} );

	test( 'the ability takes whole forints plainly and fillér with two decimals', () => {
		expect( amountString( 3000000 ) ).toBe( '30000' );
		expect( amountString( 100050 ) ).toBe( '1000.50' );
		expect( amountString( 100005 ) ).toBe( '1000.05' );
	} );
} );

describe( 'the balance before and after', () => {
	test( 'a payment lowers it, a refund raises it', () => {
		expect( balanceAfter( 3000000, 1000000, false ) ).toBe( 2000000 );
		expect( balanceAfter( 3000000, 1000000, true ) ).toBe( 4000000 );
	} );

	test( 'a payment past the balance overpays', () => {
		expect( balanceAfter( 500000, 800000, false ) ).toBe( -300000 );
	} );

	test( 'there is none without a valid amount or a payer', () => {
		expect( balanceAfter( 3000000, null, false ) ).toBeNull();
		expect( balanceAfter( 3000000, 0, false ) ).toBeNull();
		expect( balanceAfter( null, 1000, false ) ).toBeNull();
	} );
} );

describe( 'dates in the site time zone', () => {
	test( 'a value date is the day\'s 00:00 in the site zone, so its UTC instant is the day before', () => {
		expect( formatDay( '2026-12-04T23:00:00Z', 'Europe/Budapest' ) ).toBe( '2026. 12. 05.' );
		expect( formatDay( '2026-07-14T22:00:00Z', 'Europe/Budapest' ) ).toBe( '2026. 07. 15.' );
	} );

	test( 'a zone given as an offset needs no help from Intl', () => {
		expect( formatDay( '2026-12-04T23:00:00Z', '+01:00' ) ).toBe( '2026. 12. 05.' );
		expect( formatDay( '2026-12-05T01:00:00Z', '-02:00' ) ).toBe( '2026. 12. 04.' );
		expect( formatDay( '2026-12-04T23:00:00Z', 'UTC' ) ).toBe( '2026. 12. 04.' );
		expect( formatDay( '2026-12-04T23:00:00Z', '' ) ).toBe( '2026. 12. 04.' );
	} );

	test( 'no instant shows nothing', () => {
		expect( formatDay( null, 'Europe/Budapest' ) ).toBe( '' );
	} );

	test( 'today is the site zone\'s day, not UTC\'s', () => {
		const late = new Date( '2026-11-19T23:30:00Z' );
		expect( today( 'Europe/Budapest', late ) ).toBe( '2026-11-20' );
		expect( today( '+01:00', late ) ).toBe( '2026-11-20' );
		expect( today( 'UTC', late ) ).toBe( '2026-11-19' );
	} );
} );

describe( 'the recording dialog\'s checks', () => {
	const ok = { several: false, payer: 'participant', amount: '5 000', date: '2026-11-19', method: 'cash', note: '', refund: false };
	const day = '2026-11-19';

	test( 'a complete payment has no errors', () => {
		expect( validateEntry( ok, day ) ).toEqual( {} );
	} );

	test( 'the amount is required, and above zero', () => {
		expect( validateEntry( { ...ok, amount: '' }, day ).amount ).toBe( MONEY.amountEmpty );
		expect( validateEntry( { ...ok, amount: '  ' }, day ).amount ).toBe( MONEY.amountEmpty );
		expect( validateEntry( { ...ok, amount: '0' }, day ).amount ).toBe( MONEY.amountBad );
		expect( validateEntry( { ...ok, amount: 'sok' }, day ).amount ).toBe( MONEY.amountBad );
	} );

	test( 'the future is refused, today and the past are not', () => {
		expect( validateEntry( { ...ok, date: '2026-11-20' }, day ).value_date ).toBe( MONEY.dateFuture );
		expect( validateEntry( { ...ok, date: '2026-11-19' }, day ) ).toEqual( {} );
		expect( validateEntry( { ...ok, date: '2025-01-01' }, day ) ).toEqual( {} );
		expect( validateEntry( { ...ok, date: '' }, day ).value_date ).toBe( MONEY.dateEmpty );
	} );

	test( 'the method has no default and is required', () => {
		expect( validateEntry( { ...ok, method: '' }, day ).method ).toBe( MONEY.methodEmpty );
	} );

	test( 'the payer is asked only with several payers', () => {
		expect( validateEntry( { ...ok, payer: '' }, day ) ).toEqual( {} );
		expect( validateEntry( { ...ok, several: true, payer: '' }, day ).payer ).toBe( MONEY.payerEmpty );
		expect( validateEntry( { ...ok, several: true, payer: 'participant' }, day ) ).toEqual( {} );
	} );

	test( 'a correction needs its reason, a payment does not', () => {
		expect( validateEntry( { ...ok, refund: true }, day ).note ).toBe( MONEY.noteRequired );
		expect( validateEntry( { ...ok, refund: true, note: '   ' }, day ).note ).toBe( MONEY.noteRequired );
		expect( validateEntry( { ...ok, refund: true, note: 'Téves befizetés' }, day ) ).toEqual( {} );
		expect( validateEntry( { ...ok, note: '' }, day ) ).toEqual( {} );
	} );

	test( 'every wrong field is reported at once', () => {
		const errors = validateEntry( { several: true, payer: '', amount: '', date: '2026-12-01', method: '', note: '', refund: true }, day );
		expect( Object.keys( errors ).sort() ).toEqual( [ 'amount', 'method', 'note', 'payer', 'value_date' ] );
	} );
} );

describe( 'methodsFor', () => {
	const methods = [
		{ value: 'bank_transfer', label: 'Átutalás' },
		{ value: 'cash', label: 'Készpénz' },
		{ value: 'card_terminal', label: 'Bankkártya' },
	];

	it( 'offers all methods for a payment', () => {
		expect( methodsFor( { methods, refund_methods: [ methods[ 0 ] ] }, false ) ).toEqual( methods );
	} );

	it( 'uses the read refund_methods for a refund when present', () => {
		const refundMethods = [ { value: 'cash', label: 'Készpénz' } ];
		expect( methodsFor( { methods, refund_methods: refundMethods }, true ) ).toEqual( refundMethods );
	} );

	it( 'falls back to the methods without the card terminal when refund_methods is absent', () => {
		expect( methodsFor( { methods }, true ).map( ( m ) => m.value ) ).toEqual( [ 'bank_transfer', 'cash' ] );
	} );
} );
