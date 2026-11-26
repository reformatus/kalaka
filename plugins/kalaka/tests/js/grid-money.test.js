import {
	allowedTypes,
	amountString,
	balanceAfter,
	buildCall,
	canCorrect,
	entryColumns as columnsOf,
	formatBalance,
	formatBalanceMinor,
	formatDay,
	formatMinor,
	formatMoney,
	lineNote,
	lineSide,
	pairPreview,
	parseAmount,
	previewAfter,
	raisesDebt,
	savedText,
	toMinor,
	today,
	typeWord,
	validateEntry,
} from '../../src/Admin/app/grid/money';
import { MONEY } from '../../src/Admin/app/grid/texts';

const NBSP = ' ';

// The read always sends `side`; a test line that names only its kind gets the side the server gives that kind.
const PAID_KINDS = [ 'payment', 'manual_payment', 'refund', 'manual_refund' ];
const entryColumns = ( line, currency ) =>
	columnsOf( { ...line, side: line.side || ( PAID_KINDS.includes( line.kind ) ? 'paid' : 'owed' ) }, currency );

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

	test( 'an entry\'s amount is signed: a minus means the opposite of its column (topic 6a G11, topic 10)', () => {
		// The ledger stores a payment as negative and a refund as positive; the panel flips the paid side.
		const col = ( amount, kind, side ) => entryColumns( { amount, kind, side } );
		expect( col( '-35000.00000000', 'manual_payment' ).paid ).toBe( `35${ NBSP }000${ NBSP }Ft` );
		expect( col( '1000.00000000', 'manual_refund' ).paid ).toBe( `−1${ NBSP }000${ NBSP }Ft` );
		expect( col( '1000.00000000', 'refund' ).paid ).toBe( `−1${ NBSP }000${ NBSP }Ft` );
		// The read's own `side` decides, whatever the kind says.
		expect( col( '1000.00000000', 'refund', 'paid' ).paid ).toBe( `−1${ NBSP }000${ NBSP }Ft` );
		expect( col( '1000.00000000', 'refund', 'owed' ).owed ).toBe( `1${ NBSP }000${ NBSP }Ft` );
		// A charge is shown as stored, positive like „Fizetendő”.
		expect( col( '30000.00000000', 'charge' ).owed ).toBe( `30${ NBSP }000${ NBSP }Ft` );
		expect( col( '0.00000000', 'charge' ).owed ).toBe( `0${ NBSP }Ft` );
	} );

	test( 'a minus means the opposite of the column: a discount in „Fizetendő”, a refund in „Befizetett”', () => {
		expect( formatMoney( '-5000.00000000' ) ).toBe( `−5${ NBSP }000${ NBSP }Ft` );
		expect( formatMoney( '30000.00000000' ) ).toBe( `30${ NBSP }000${ NBSP }Ft` );
		expect( formatMoney( '0.005' ) ).toBe( `0${ NBSP }Ft` );
		expect( formatMoney( '30000.00000000', 'EUR' ) ).toBe( `30${ NBSP }000${ NBSP }EUR` );
		expect( formatMinor( 100050, 'EUR' ) ).toBe( `1${ NBSP }000,50${ NBSP }EUR` );
		expect( formatMinor( 100050, 'HUF' ) ).toBe( `1${ NBSP }000,50${ NBSP }Ft` );
	} );

	test( 'no amount ever carries a plus sign', () => {
		[ '30000', '-30000', '0', '1000.50' ].forEach( ( v ) => {
			expect( formatMoney( v ) ).not.toContain( '+' );
			[ 'charge', 'payment', 'refund', 'manual_discount' ].forEach( ( k ) => {
				const cols = entryColumns( { amount: v, kind: k } );
				expect( cols.owed + cols.paid ).not.toContain( '+' );
			} );
		} );
		expect( formatBalanceMinor( 100 ) ).not.toContain( '+' );
	} );
} );

describe( '„Tartozás”: positive still owed, a negative value is an overpayment', () => {
	test( 'positive and zero are bare, a minus carries „(túlfizetés)” in lower case', () => {
		expect( formatBalance( '30000.00000000' ) ).toBe( `30${ NBSP }000${ NBSP }Ft` );
		expect( formatBalance( '0.00000000' ) ).toBe( `0${ NBSP }Ft` );
		expect( formatBalance( '-5000.00000000' ) ).toBe( `−5${ NBSP }000${ NBSP }Ft (túlfizetés)` );
		expect( formatBalanceMinor( -100050 ) ).toBe( `−1${ NBSP }000,50${ NBSP }Ft (túlfizetés)` );
		expect( MONEY.overpaid ).toBe( 'túlfizetés' );
	} );

	test( 'the word stays out of „Fizetendő” and „Befizetett”', () => {
		expect( formatMoney( '-5000.00000000' ) ).not.toContain( MONEY.overpaid );
		expect( entryColumns( { amount: '1000.00000000', kind: 'refund' } ).paid ).not.toContain( MONEY.overpaid );
	} );

	test( 'the owner\'s words for the payer table and the preview', () => {
		expect( [ MONEY.payer, MONEY.owed, MONEY.paid, MONEY.balance, MONEY.total ] ).toEqual( [
			'Fizető',
			'Fizetendő',
			'Befizetett',
			'Tartozás',
			'Összesen',
		] );
		expect( [ MONEY.balanceNow, MONEY.balanceAfter ] ).toEqual( [ 'Jelenlegi tartozás', 'Tartozás mentés után' ] );
	} );
} );

describe( 'the two sides of the entries', () => {
	const c = ( amount, kind ) => entryColumns( { amount, kind } );

	test( 'an owed-side line shows in „Fizetendő” as stored, a paid-side line in „Befizetett”', () => {
		expect( c( '30000.00000000', 'charge' ) ).toEqual( { owed: `30${ NBSP }000${ NBSP }Ft`, paid: '' } );
		expect( c( '2000.00000000', 'manual_charge' ) ).toEqual( { owed: `2${ NBSP }000${ NBSP }Ft`, paid: '' } );
		expect( c( '-500.00000000', 'manual_discount' ) ).toEqual( { owed: `−500${ NBSP }Ft`, paid: '' } );
		expect( c( '-35000.00000000', 'manual_payment' ) ).toEqual( { owed: '', paid: `35${ NBSP }000${ NBSP }Ft` } );
		expect( c( '-35000.00000000', 'payment' ) ).toEqual( { owed: '', paid: `35${ NBSP }000${ NBSP }Ft` } );
		expect( c( '1000.00000000', 'manual_refund' ) ).toEqual( { owed: '', paid: `−1${ NBSP }000${ NBSP }Ft` } );
		expect( c( '1000.00000000', 'refund' ) ).toEqual( { owed: '', paid: `−1${ NBSP }000${ NBSP }Ft` } );
	} );

	test( 'a correction is a payment fact: positive when it raises „Befizetett”, a minus when it lowers it', () => {
		// „in” is stored as a negative manual_payment, „out” as a positive manual_refund.
		expect( c( '-1000.00000000', 'manual_payment' ).paid ).toBe( `1${ NBSP }000${ NBSP }Ft` );
		expect( c( '1000.00000000', 'manual_refund' ).paid ).toBe( `−1${ NBSP }000${ NBSP }Ft` );
	} );

	test( 'the read\'s own `side` drives the column, whatever the kind', () => {
		const line = ( amount, type, side ) => entryColumns( { amount, type, side, kind: type } );
		expect( line( '30000.00000000', 'fee', 'owed' ) ).toEqual( { owed: `30${ NBSP }000${ NBSP }Ft`, paid: '' } );
		expect( line( '1500.00000000', 'component', 'owed' ).owed ).toBe( `1${ NBSP }500${ NBSP }Ft` );
		expect( line( '-500.00000000', 'manual_discount', 'owed' ).owed ).toBe( `−500${ NBSP }Ft` );
		expect( line( '-35000.00000000', 'manual_payment', 'paid' ) ).toEqual( { owed: '', paid: `35${ NBSP }000${ NBSP }Ft` } );
		expect( line( '1000.00000000', 'refund', 'paid' ).paid ).toBe( `−1${ NBSP }000${ NBSP }Ft` );
		expect( lineSide( { side: 'paid', kind: 'charge' } ) ).toBe( 'paid' );
	} );

	test( 'a line with a kind the panel does not know (extra, support lines) is placed by the read\'s side, as stored', () => {
		expect( c( '-10000.00000000', 'support' ) ).toEqual( { owed: `−10${ NBSP }000${ NBSP }Ft`, paid: '' } );
		expect( c( '5000.00000000', 'adjustment' ).owed ).toBe( `5${ NBSP }000${ NBSP }Ft` );
		expect( columnsOf( { amount: '-5000.00000000', kind: 'extra_charge', side: 'paid' } ).paid ).toBe( `5${ NBSP }000${ NBSP }Ft` );
	} );

	test( 'the type word is the read\'s own label, and nothing is guessed when it gives none', () => {
		expect( typeWord( { kind: 'charge', kind_label: 'Díj' } ) ).toBe( 'Díj' );
		expect( typeWord( { kind: 'extra_charge', kind_label: 'Étkezés' } ) ).toBe( 'Étkezés' );
		expect( typeWord( { type: 'fee' } ) ).toBe( '' );
		expect( typeWord( { kind: 'manual_charge' } ) ).toBe( '' );
	} );

	test( '„Megjegyzés” shows a charge\'s title, else the note', () => {
		expect( lineNote( { title: 'Késedelmi díj', note: '' } ) ).toBe( 'Késedelmi díj' );
		expect( lineNote( { note: 'Közlemény' } ) ).toBe( 'Közlemény' );
		expect( lineNote( {} ) ).toBe( '' );
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

describe( '„Tartozás” before and after', () => {
	test( 'a payment lowers it, a refund raises it', () => {
		expect( balanceAfter( 3000000, 1000000, false ) ).toBe( 2000000 );
		expect( balanceAfter( 3000000, 1000000, true ) ).toBe( 4000000 );
	} );

	test( 'a payment past what is owed overpays', () => {
		expect( balanceAfter( 500000, 800000, false ) ).toBe( -300000 );
	} );

	test( 'there is none without a valid amount or a payer', () => {
		expect( balanceAfter( 3000000, null, false ) ).toBeNull();
		expect( balanceAfter( 3000000, 0, false ) ).toBeNull();
		expect( balanceAfter( null, 1000, false ) ).toBeNull();
	} );

	test( 'the preview follows the type: Befizetés and Kedvezmény lower it, Visszafizetés and Díj raise it', () => {
		const at = ( kind, extra = {} ) => previewAfter( { before: 3000000, minor: 1000000, kind, direction: '', ...extra } );
		expect( at( 'payment' ) ).toBe( 2000000 );
		expect( at( 'refund' ) ).toBe( 4000000 );
		expect( at( 'charge' ) ).toBe( 4000000 );
		expect( at( 'discount' ) ).toBe( 2000000 );
		expect( [ 'payment', 'refund', 'charge', 'discount' ].map( ( k ) => raisesDebt( k, '' ) ) ).toEqual( [
			false,
			true,
			true,
			false,
		] );
	} );

	test( 'the correction\'s preview follows its direction: more „Befizetett” lowers it, less raises it', () => {
		const fix = ( direction ) => previewAfter( { before: 3000000, minor: 1000000, kind: 'correction', direction } );
		expect( fix( 'in' ) ).toBe( 2000000 );
		expect( fix( 'out' ) ).toBe( 4000000 );
		expect( fix( '' ) ).toBeNull();
	} );

	test( 'no type, no amount: no preview', () => {
		expect( previewAfter( { before: 3000000, minor: 1000000, kind: '' } ) ).toBeNull();
		expect( previewAfter( { before: 3000000, minor: null, kind: 'charge' } ) ).toBeNull();
	} );

	test( 'an overpayment in the preview reads „(túlfizetés)”', () => {
		const after = previewAfter( { before: 500000, minor: 800000, kind: 'payment' } );
		expect( formatBalanceMinor( after ) ).toBe( `−3${ NBSP }000${ NBSP }Ft (túlfizetés)` );
	} );

	test( 'a „completed immediately” pair nets to no change, and shows both lines with their effect', () => {
		expect( previewAfter( { before: 3000000, minor: 500000, kind: 'discount', completed: true } ) ).toBe( 3000000 );
		expect( previewAfter( { before: 3000000, minor: 500000, kind: 'charge', completed: true } ) ).toBe( 3000000 );
		expect( pairPreview( { kind: 'charge', minor: 500000 } ) ).toEqual( [
			{ kind: 'charge', effect: 500000 },
			{ kind: 'payment', effect: -500000 },
		] );
		expect( pairPreview( { kind: 'discount', minor: 500000 } ) ).toEqual( [
			{ kind: 'discount', effect: -500000 },
			{ kind: 'refund', effect: 500000 },
		] );
		expect( pairPreview( { kind: 'payment', minor: 500000 } ) ).toEqual( [] );
		expect( pairPreview( { kind: 'charge', minor: null } ) ).toEqual( [] );
	} );
} );

describe( 'which types the „Tétel rögzítése” dialog offers', () => {
	const ALL4 = [ 'payment', 'refund', 'charge', 'discount' ];
	const money = { record_money: true, record_charge: true, record_discount: true };

	test( 'the server\'s flags offer all four types', () => {
		expect( allowedTypes( money ) ).toEqual( ALL4 );
	} );

	test( 'a registered row with record_charge false offers no Díj, with record_discount false no Kedvezmény', () => {
		expect( allowedTypes( { ...money, record_charge: false } ) ).toEqual( [ 'payment', 'refund', 'discount' ] );
		expect( allowedTypes( { ...money, record_discount: false } ) ).toEqual( [ 'payment', 'refund', 'charge' ] );
		expect( allowedTypes( { record_money: true } ) ).toEqual( [ 'payment', 'refund' ] );
	} );

	test( 'Díj and Kedvezmény follow only their own flags, whatever the rest says', () => {
		expect( allowedTypes( { record_charge: true } ) ).toEqual( [ 'charge' ] );
		expect( allowedTypes( { record_discount: true } ) ).toEqual( [ 'discount' ] );
	} );

	test( 'the old flags are not read', () => {
		expect( allowedTypes( { record_payment: true, record_refund: true, record_correction: true } ) ).toEqual( [] );
	} );

	test( 'every combination of the three flags offers exactly the right types', () => {
		const flags = [ 'record_money', 'record_charge', 'record_discount' ];
		for ( let mask = 0; mask < 8; mask++ ) {
			const can = {};
			flags.forEach( ( f, i ) => {
				can[ f ] = !! ( mask & ( 1 << i ) );
			} );
			const m = !! ( mask & 1 );
			expect( allowedTypes( can ) ).toEqual(
				ALL4.filter( ( t ) => ( { payment: m, refund: m, charge: can.record_charge, discount: can.record_discount }[ t ] ) )
			);
		}
	} );

	test( 'a holder of none is offered nothing', () => {
		expect( allowedTypes( {} ) ).toEqual( [] );
		expect( allowedTypes( undefined ) ).toEqual( [] );
		expect( allowedTypes( { record_money: false } ) ).toEqual( [] );
	} );

	test( 'the correction is allowed by the money-recording permission only', () => {
		expect( canCorrect( { record_money: true } ) ).toBe( true );
		expect( canCorrect( { record_correction: true } ) ).toBe( false );
		expect( canCorrect( { record_payment: true } ) ).toBe( false );
		expect( canCorrect( { record_charge: true } ) ).toBe( false );
		expect( canCorrect( undefined ) ).toBe( false );
	} );
} );

describe( 'the call for each kind of entry', () => {
	const entry = {
		code: '02S2',
		currency: 'HUF',
		payer: 'participant',
		amount: '500',
		valueDate: null,
		method: null,
		note: '',
		direction: null,
		title: '',
		completed: false,
		key: 'ui-k',
	};
	const all = { record_money: true, record_charge: true, record_discount: true };

	test( 'a charge or a discount is never called unless the read declares it', () => {
		const old = { record_payment: true, record_refund: true, record_correction: true };
		expect( () => buildCall( 7, { ...entry, kind: 'charge', title: 'X' }, old ) ).toThrow();
		expect( () => buildCall( 7, { ...entry, kind: 'discount', title: 'X' }, old ) ).toThrow();
		// The server's flags decide: a row whose read says no is never called.
		expect( () => buildCall( 7, { ...entry, kind: 'charge', title: 'X' }, { record_money: true } ) ).toThrow();
		expect( () => buildCall( 7, { ...entry, kind: 'discount', title: 'X' }, { record_money: true, record_charge: true } ) ).toThrow();
		expect( () => buildCall( 7, { ...entry, kind: 'payment' }, {} ) ).toThrow();
		expect( () => buildCall( 7, { ...entry, kind: 'correction', direction: 'in', note: 'x' }, {} ) ).toThrow();
	} );

	test( 'a charge and a discount carry a title and no method or date', () => {
		expect( buildCall( 7, { ...entry, kind: 'charge', title: 'Pótdíj' }, all ) ).toEqual( {
			ability: 'kalaka/record-charge',
			input: {
				event_id: 7,
				code: '02S2',
				payer: 'participant',
				amount: '500',
				currency: 'HUF',
				idempotency_key: 'ui-k',
				title: 'Pótdíj',
			},
		} );
		expect( buildCall( 7, { ...entry, kind: 'discount', title: 'Elengedés' }, all ).ability ).toBe(
			'kalaka/record-discount'
		);
	} );

	test( '„completed immediately” is one call with the paid-side line\'s method, date and Közlemény', () => {
		const call = buildCall(
			7,
			{ ...entry, kind: 'discount', title: 'Elengedés', completed: true, method: 'transfer', valueDate: '2026-11-21', note: 'Elengedés' },
			all
		);
		expect( call.ability ).toBe( 'kalaka/record-discount' );
		expect( call.input ).toMatchObject( {
			title: 'Elengedés',
			completed: true,
			method: 'transfer',
			value_date: '2026-11-21',
			note: 'Elengedés',
		} );
	} );

	test( 'the snackbar per type, and for a saved pair', () => {
		expect( [ 'payment', 'refund', 'charge', 'discount', 'correction' ].map( ( k ) => savedText( k, false ) ) ).toEqual( [
			'Befizetés rögzítve.',
			'Visszafizetés rögzítve.',
			'Díj rögzítve.',
			'Kedvezmény rögzítve.',
			'Korrekció rögzítve.',
		] );
		expect( savedText( 'charge', true ) ).toBe( MONEY.savedChargePaid );
		expect( savedText( 'discount', true ) ).toBe( MONEY.savedDiscountRefund );
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
	const ok = { several: false, payer: 'participant', amount: '5 000', date: '2026-11-19', method: 'cash', note: '', title: '', kind: 'payment', direction: '', completed: false };
	const day = '2026-11-19';

	test( 'a complete payment has no errors', () => {
		expect( validateEntry( ok, day ) ).toEqual( {} );
	} );

	test( 'the type has no default and is required: „Válassz típust.”', () => {
		expect( validateEntry( { ...ok, kind: '' }, day ).type ).toBe( 'Válassz típust.' );
		expect( MONEY.typeEmpty ).toBe( 'Válassz típust.' );
	} );

	test( 'with no type picked the type error is the only one, whatever else is empty (N56)', () => {
		const blank = { several: true, payer: '', amount: '', date: '', method: '', note: '', title: '', kind: '', direction: '', completed: false };
		expect( validateEntry( blank, day ) ).toEqual( { type: 'Válassz típust.' } );
		// A correction has its own field errors, as before.
		expect( Object.keys( validateEntry( { ...blank, kind: 'correction' }, day ) ).sort() ).toEqual( [ 'amount', 'direction', 'payer' ] );
	} );

	test( 'a charge and a discount need a title and no method or date', () => {
		const charge = { ...ok, kind: 'charge', method: '', date: '', title: 'Pótdíj' };
		expect( validateEntry( charge, day ) ).toEqual( {} );
		expect( validateEntry( { ...charge, kind: 'discount' }, day ) ).toEqual( {} );
		expect( validateEntry( { ...charge, title: '  ' }, day ).title ).toBe( MONEY.titleEmpty );
		expect( validateEntry( { ...charge, title: undefined }, day ).title ).toBe( MONEY.titleEmpty );
		expect( validateEntry( { ...charge, amount: '' }, day ).amount ).toBe( MONEY.amountEmpty );
	} );

	test( '„completed immediately” asks the paid-side line\'s method and date as well', () => {
		const pair = { ...ok, kind: 'discount', title: 'Elengedés', completed: true, method: '', date: '' };
		const errors = validateEntry( pair, day );
		expect( Object.keys( errors ).sort() ).toEqual( [ 'method', 'value_date' ] );
		expect( validateEntry( { ...pair, method: 'transfer', date: '2026-11-19' }, day ) ).toEqual( {} );
		expect( validateEntry( { ...pair, method: 'transfer', date: '2026-11-20' }, day ).value_date ).toBe( MONEY.dateFuture );
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

	test( 'a refund and a payment use the same checks: a method and a date, an optional note', () => {
		const refund = { ...ok, kind: 'refund' };
		expect( validateEntry( refund, day ) ).toEqual( {} );
		expect( validateEntry( { ...refund, method: 'card_terminal' }, day ) ).toEqual( {} );
		expect( validateEntry( { ...refund, method: '' }, day ).method ).toBe( MONEY.methodEmpty );
		expect( validateEntry( { ...refund, date: '2026-11-20' }, day ).value_date ).toBe( MONEY.dateFuture );
	} );

	test( 'the correction\'s direction words (the lektor\'s form) and its refusal', () => {
		expect( MONEY.directionIn ).toBe( 'Befizetett összeg növelése' );
		expect( MONEY.directionOut ).toBe( 'Befizetett összeg csökkentése' );
		expect( MONEY.directionEmpty ).toBe( 'Válassz irányt.' );
	} );

	test( 'a correction needs a direction, and its reason is optional, and no method or date', () => {
		const fix = { ...ok, kind: 'correction', method: '', date: '', direction: 'out', note: 'Téves befizetés' };
		expect( validateEntry( fix, day ) ).toEqual( {} );
		expect( validateEntry( { ...fix, direction: 'in' }, day ) ).toEqual( {} );
		expect( validateEntry( { ...fix, direction: '' }, day ).direction ).toBe( MONEY.directionEmpty );
		expect( validateEntry( { ...fix, note: '   ' }, day ) ).toEqual( {} );
		expect( validateEntry( { ...fix, note: '' }, day ) ).toEqual( {} );
		expect( validateEntry( { ...fix, amount: '0' }, day ).amount ).toBe( MONEY.amountBad );
	} );

	test( 'every wrong field is reported at once', () => {
		const errors = validateEntry( { several: true, payer: '', amount: '', date: '2026-12-01', method: '', note: '', kind: 'payment', direction: '' }, day );
		expect( Object.keys( errors ).sort() ).toEqual( [ 'amount', 'method', 'payer', 'value_date' ] );
	} );
} );
