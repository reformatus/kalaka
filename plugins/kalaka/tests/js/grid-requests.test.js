/**
 * The requests block's pure logic (slice B): the state lines, when the dialog asks for an amount, the one check
 * and the ability's input. Every string is unapproved until the owner ticks its box: to
 * [UNAPPROVED T11], see `REQUESTS`.
 */
import {
	aidFigures,
	asksAmount,
	buildAnswer,
	hintedAmount,
	itemsOf,
	savedText,
	stateText,
	validateAnswer,
	validateNote,
	warnsFull,
} from '../../src/Admin/app/grid/requests';
import { MONEY, REQUESTS } from '../../src/Admin/app/grid/texts';

const offer = { id: 'offer:church', kind: 'offer', label: 'Egyházi támogatás', state: 'awaiting', amount: null };
const aid = { id: 'aid', kind: 'aid', label: 'Támogatási kérelem', state: 'awaiting', amount: null };

describe( 'the requests of a read', () => {
	test( 'are its items, or none when the read does not carry them', () => {
		expect( itemsOf( { requests: { currency: 'HUF', items: [ offer ] } } ) ).toEqual( [ offer ] );
		expect( itemsOf( {} ) ).toEqual( [] );
		expect( itemsOf( undefined ) ).toEqual( [] );
	} );
} );

describe( 'the state line', () => {
	test( 'says awaiting, approved or refused', () => {
		expect( stateText( offer, 'HUF' ) ).toBe( REQUESTS.awaiting );
		expect( stateText( { ...offer, state: 'approved' }, 'HUF' ) ).toBe( REQUESTS.approved );
		expect( stateText( { ...offer, state: 'refused' }, 'HUF' ) ).toBe( REQUESTS.refused );
	} );

	test( 'shows „–” for an inactive request, never „Válaszra vár” or „Nem” (N655, N1236, T1224)', () => {
		expect( stateText( { ...offer, state: 'inactive' }, 'HUF' ) ).toBe( '–' );
	} );

	test( 'shows the award of a granted aid and „Elutasítva” for an award of zero', () => {
		const granted = { ...aid, state: 'approved', amount: '12000.00000000' };
		expect( stateText( granted, 'HUF' ) ).toBe( 'Jóváhagyva (12\u00a0000\u00a0Ft)' );
		expect( stateText( { ...aid, state: 'refused', amount: '0.00000000' }, 'HUF' ) ).toBe( REQUESTS.refused );
	} );
} );

describe( 'the dialog', () => {
	test( 'asks for an amount only for an aid yes', () => {
		expect( asksAmount( aid, 'yes' ) ).toBe( true );
		expect( asksAmount( aid, 'no' ) ).toBe( false );
		expect( asksAmount( offer, 'yes' ) ).toBe( false );
	} );

	test( 'warns before a yes to a refused offer whose pool is full, and at no other time', () => {
		const full = { ...offer, state: 'refused', seat_full: true };
		expect( warnsFull( full, 'yes' ) ).toBe( true );
		expect( warnsFull( full, 'no' ) ).toBe( false );
		expect( warnsFull( { ...offer, seat_full: false }, 'yes' ) ).toBe( false );
	} );

	test( 'needs an amount above zero for an aid yes, and nothing else', () => {
		expect( validateAnswer( aid, 'yes', '' ) ).toBe( REQUESTS.awardEmpty );
		expect( validateAnswer( aid, 'yes', '0' ) ).toBe( MONEY.amountBad );
		expect( validateAnswer( aid, 'yes', 'abc' ) ).toBe( MONEY.amountBad );
		expect( validateAnswer( aid, 'yes', '12 000' ) ).toBe( '' );
		expect( validateAnswer( aid, 'no', '' ) ).toBe( '' );
		expect( validateAnswer( offer, 'yes', '' ) ).toBe( '' );
	} );

	test( 'never requires the note, whichever way the answer goes (N1183)', () => {
		expect( validateNote( offer, 'yes', '' ) ).toBe( '' );
		expect( validateNote( offer, 'yes', '   ' ) ).toBe( '' );
		expect( validateNote( offer, 'yes', 'Telefonon megerősítve' ) ).toBe( '' );
		expect( validateNote( offer, 'no', '' ) ).toBe( '' );
		expect( validateNote( aid, 'yes', '' ) ).toBe( '' );
	} );

	test( 'builds the ability input: the amount for an aid yes, the note only with text', () => {
		expect( buildAnswer( 5, '00A0', aid, 'yes', '12 000', ' Teszt ' ) ).toEqual( {
			event_id: 5,
			code: '00A0',
			request: 'aid',
			answer: 'yes',
			amount: '12000',
			note: 'Teszt',
		} );
		expect( buildAnswer( 5, '00A0', offer, 'no', '', '  ' ) ).toEqual( {
			event_id: 5,
			code: '00A0',
			request: 'offer:church',
			answer: 'no',
		} );
	} );
} );

describe( 'the aid context (walkthrough 6.8)', () => {
	const figures = {
		full_price: '30000.00000000',
		can_pay: null,
		asked: '12000.00000000',
		max_award: '30000.00000000',
	};

	test( 'shows the figures the read carries, and none without them', () => {
		const shown = aidFigures( { ...aid, aid: figures } );
		expect( shown.map( ( figure ) => figure.key ) ).toEqual( [ 'full_price', 'asked', 'max_award' ] );
		expect( shown.map( ( figure ) => figure.label ) ).toEqual( [
			REQUESTS.figures.full_price,
			REQUESTS.figures.asked,
			REQUESTS.figures.max_award,
		] );
		expect( aidFigures( aid ) ).toEqual( [] );
		expect( aidFigures( { ...offer, aid: figures } ) ).toEqual( [] );
	} );

	test( 'hints the asked amount, and nothing without it', () => {
		expect( hintedAmount( { ...aid, aid: figures } ) ).toBe( '12000' );
		expect( hintedAmount( aid ) ).toBe( '' );
		expect( hintedAmount( { ...aid, aid: { ...figures, asked: null } } ) ).toBe( '' );
	} );

	test( 'refuses a yes above the full price with the act\'s own sentence, and only then', () => {
		const held = { ...aid, aid: figures };
		expect( validateAnswer( held, 'yes', '12000' ) ).toBe( '' );
		expect( validateAnswer( held, 'yes', '30000' ) ).toBe( '' );
		expect( validateAnswer( held, 'yes', '30000.5' ) ).toBe( REQUESTS.aidAbovePrice );
		expect( REQUESTS.aidAbovePrice ).toBe( 'A támogatás nem lehet több a fizetendő összegnél.' );
		// Without the figures the amount checks stay and the act still refuses.
		expect( validateAnswer( aid, 'yes', '999999' ) ).toBe( '' );
	} );
} );

describe( 'the snackbar', () => {
	test( 'says the answer was recorded only when the act changed something', () => {
		expect( savedText( { changed: true, answer: 7, over_limit: false } ) ).toBe( REQUESTS.saved );
		expect( savedText( { changed: false, answer: null, over_limit: false } ) ).toBe( '' );
	} );
} );

describe( 'validateNote', () => {
	test( 'counts characters, not UTF-16 units, on the trimmed text', () => {
		expect( validateNote( aid, 'no', '' ) ).toBe( '' );
		expect( validateNote( aid, 'no', 'a'.repeat( 500 ) ) ).toBe( '' );
		expect( validateNote( aid, 'no', '😀'.repeat( 500 ) ) ).toBe( '' );
		expect( validateNote( aid, 'no', '  ' + 'a'.repeat( 500 ) + '  ' ) ).toBe( '' );
		expect( validateNote( aid, 'no', 'a'.repeat( 501 ) ) ).toBe( REQUESTS.noteTooLong );
		expect( validateNote( aid, 'no', '😀'.repeat( 501 ) ) ).toBe( REQUESTS.noteTooLong );
	} );
} );
