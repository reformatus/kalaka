/**
 * The „Tétel rögzítése” dialog (topic 6a G12, topic 10): which types it offers for each grant (Befizetés,
 * Visszafizetés, Díj, Kedvezmény), that none is preselected unless only one is allowed, which ability and
 * snackbar each type triggers, the Díj/Kedvezmény title and the „completed immediately” checkbox, and the
 * preview.
 *
 * Jest has no React DOM helper here, so the test renders the real RecordDialog with small stand-ins for
 * wp-components; `api.js` and its `@wordpress/api-fetch` stay real, which is what picks the ability.
 */
import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';
import RecordDialog from '../../src/Admin/app/grid/RecordDialog';
import { MONEY } from '../../src/Admin/app/grid/texts';

jest.mock( '@wordpress/components', () => {
	const { createElement: h } = require( 'react' );
	const RadioControl = ( { label, selected, options, onChange, help } ) =>
		h(
			'fieldset',
			null,
			h( 'legend', null, label ),
			options.map( ( o ) =>
				h(
					'label',
					{ key: o.value },
					h( 'input', {
						type: 'radio',
						name: label,
						value: o.value,
						checked: selected === o.value,
						onChange: () => onChange( o.value ),
					} ),
					o.label
				)
			),
			help
		);
	const TextControl = ( { label, value, onChange, help, type } ) =>
		h(
			'label',
			null,
			label,
			h( 'input', {
				'aria-label': label,
				type: type || 'text',
				value,
				onChange: ( e ) => onChange( e.target.value ),
			} ),
			help
		);
	const CheckboxControl = ( { label, checked, onChange, help } ) =>
		h(
			'label',
			null,
			h( 'input', {
				type: 'checkbox',
				'aria-label': label,
				checked,
				onChange: ( e ) => onChange( e.target.checked ),
			} ),
			label,
			help
		);
	const Button = ( { children, ...rest } ) => {
		delete rest.isBusy;
		return h( 'button', rest, children );
	};
	const Modal = ( { title, children } ) =>
		h( 'div', { role: 'dialog', 'aria-label': title }, h( 'h1', null, title ), children );
	const Notice = ( { children } ) => h( 'div', null, children );
	return { Button, CheckboxControl, Modal, Notice, RadioControl, TextControl };
} );

jest.mock(
	'@wordpress/api-fetch',
	() => ( {
		__esModule: true,
		default: jest.fn( () => Promise.resolve( { id: 1 } ) ),
	} ),
	{ virtual: true }
);

window.IS_REACT_ACT_ENVIRONMENT = true;

const NBSP = '\u00a0';
const money = {
	payers: [ { payer: 'participant', label: 'Jelentkező', charged: '30000', paid: '0', balance: '30000' } ],
	methods: [
		{ value: 'cash', label: 'Készpénz' },
		{ value: 'transfer', label: 'Átutalás' },
	],
	currency: 'HUF',
};

let host;
let root;
let done;

async function render( can, mode = 'entry', moneyRead = money ) {
	host = document.createElement( 'div' );
	document.body.appendChild( host );
	root = createRoot( host );
	done = jest.fn().mockResolvedValue( undefined );
	await act( async () => {
		root.render(
			createElement( RecordDialog, {
				config: { timezone: 'Europe/Budapest', rest: '', event: 1 },
				mode,
				can,
				code: 'X1',
				money: moneyRead,
				onClose: () => {},
				onDone: done,
			} )
		);
	} );
}

const radios = ( name ) => Array.from( host.querySelectorAll( `input[type="radio"][name="${ name }"]` ) );
const typeRadios = () => radios( MONEY.typeField );
const typeLabels = () => typeRadios().map( ( r ) => r.parentElement.textContent.trim() );
const field = ( label ) => host.querySelector( 'input[aria-label="' + label + '"]' );

async function setValue( input, value ) {
	const setter = Object.getOwnPropertyDescriptor( window.HTMLInputElement.prototype, 'value' ).set;
	await act( async () => {
		setter.call( input, value );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
}

async function pick( input ) {
	await act( async () => {
		input.click();
	} );
}

async function submit() {
	const form = host.querySelector( 'form' );
	await act( async () => {
		form.dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );
	} );
}

beforeEach( () => {
	apiFetch.mockClear();
} );

afterEach( async () => {
	if ( root ) {
		await act( async () => {
			root.unmount();
		} );
		root = null;
	}
	if ( host ) {
		host.remove();
		host = null;
	}
} );

const ALL = { record_money: true, record_charge: true, record_discount: true };
const MONEY_ONLY = { record_money: true };

describe( 'the types the dialog offers', () => {
	test( 'with only the money-recording flag there are „Befizetés” and „Visszafizetés”, none preselected', async () => {
		await render( MONEY_ONLY );
		expect( typeRadios().map( ( r ) => r.value ) ).toEqual( [ 'payment', 'refund' ] );
		expect( typeLabels() ).toEqual( [ 'Befizetés', 'Visszafizetés' ] );
		expect( typeRadios().some( ( r ) => r.checked ) ).toBe( false );
	} );

	test( 'the three money flags offer all four, none preselected', async () => {
		await render( ALL );
		expect( typeLabels() ).toEqual( [ 'Befizetés', 'Visszafizetés', 'Díj', 'Kedvezmény' ] );
		expect( typeRadios().some( ( r ) => r.checked ) ).toBe( false );
	} );

	test( 'the server\'s flags decide: a row with record_charge false offers no Díj, record_discount false no Kedvezmény', async () => {
		await render( { ...ALL, record_charge: false } );
		expect( typeLabels() ).toEqual( [ 'Befizetés', 'Visszafizetés', 'Kedvezmény' ] );
		await act( async () => root.unmount() );
		host.remove();
		await render( { ...ALL, record_discount: false } );
		expect( typeLabels() ).toEqual( [ 'Befizetés', 'Visszafizetés', 'Díj' ] );
	} );

	test( 'exactly one offered type is preselected', async () => {
		await render( { record_charge: true } );
		expect( typeRadios().map( ( r ) => [ r.value, r.checked ] ) ).toEqual( [ [ 'charge', true ] ] );
		await act( async () => root.unmount() );
		host.remove();
		await render( { record_discount: true } );
		expect( typeRadios().map( ( r ) => [ r.value, r.checked ] ) ).toEqual( [ [ 'discount', true ] ] );
	} );

	test( 'a holder of none is offered no type', async () => {
		await render( { record_money: false, record_charge: false, record_discount: false } );
		expect( typeRadios() ).toEqual( [] );
	} );

	test( '„Típus” is the first field', async () => {
		await render( ALL );
		expect( host.querySelector( 'form' ).firstElementChild.textContent ).toContain( MONEY.typeField );
	} );

	test( 'with no type picked the message is „Válassz típust.”', async () => {
		await render( ALL );
		await setValue( field( MONEY.amountField ), '5 000' );
		await submit();
		expect( host.textContent ).toContain( 'Válassz típust.' );
		expect( apiFetch ).not.toHaveBeenCalled();
	} );
} );

describe( 'what each type records', () => {
	async function fillAndSend( type, extra = async () => {} ) {
		await render( ALL );
		await pick( typeRadios().find( ( r ) => r.value === type ) );
		await setValue( field( MONEY.amountField ), '5 000' );
		await extra();
		await submit();
	}
	const paidSide = async () => {
		await pick( radios( MONEY.methodField ).find( ( r ) => r.value === 'cash' ) );
	};
	const owedSide = async () => {
		await setValue( field( MONEY.titleField ), 'Pótdíj' );
	};

	test( '„Befizetés” runs kalaka/record-payment and shows „Befizetés rögzítve.”', async () => {
		await fillAndSend( 'payment', paidSide );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		const request = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( request.path ).toBe( '/wp-abilities/v1/abilities/kalaka/record-payment/run' );
		expect( request.method ).toBe( 'POST' );
		expect( request.data.input.amount ).toBe( '5000' );
		expect( request.data.input.method ).toBe( 'cash' );
		expect( request.data.input ).not.toHaveProperty( 'direction' );
		expect( request.data.input ).not.toHaveProperty( 'reason' );
		expect( request.data.input ).not.toHaveProperty( 'title' );
		expect( done ).toHaveBeenCalledWith( 'Befizetés rögzítve.' );
	} );

	test( '„Visszafizetés” runs kalaka/record-refund and shows „Visszafizetés rögzítve.”', async () => {
		await fillAndSend( 'refund', paidSide );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		const request = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( request.path ).toBe( '/wp-abilities/v1/abilities/kalaka/record-refund/run' );
		expect( request.data.input.method ).toBe( 'cash' );
		expect( done ).toHaveBeenCalledWith( 'Visszafizetés rögzítve.' );
	} );

	test( '„Díj” and „Kedvezmény” run their own abilities with a title and no method or date', async () => {
		await fillAndSend( 'charge', owedSide );
		let request = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( request.path ).toBe( '/wp-abilities/v1/abilities/kalaka/record-charge/run' );
		expect( request.data.input ).toMatchObject( { title: 'Pótdíj', amount: '5000' } );
		expect( request.data.input ).not.toHaveProperty( 'method' );
		expect( request.data.input ).not.toHaveProperty( 'value_date' );
		expect( request.data.input ).not.toHaveProperty( 'completed' );
		expect( done ).toHaveBeenCalledWith( 'Díj rögzítve.' );

		await act( async () => root.unmount() );
		host.remove();
		apiFetch.mockClear();
		await fillAndSend( 'discount', owedSide );
		request = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( request.path ).toBe( '/wp-abilities/v1/abilities/kalaka/record-discount/run' );
		expect( done ).toHaveBeenLastCalledWith( 'Kedvezmény rögzítve.' );
	} );

	test( 'a Díj or Kedvezmény has a participant-visible title: required, with its hint, and no method or date fields', async () => {
		await render( ALL );
		await pick( typeRadios().find( ( r ) => r.value === 'discount' ) );
		expect( host.textContent ).toContain( 'Megnevezés' );
		expect( host.textContent ).toContain( 'Ezt a jelentkező is látja.' );
		expect( radios( MONEY.methodField ) ).toEqual( [] );
		expect( field( MONEY.dateField ) ).toBeNull();
		await setValue( field( MONEY.amountField ), '500' );
		await submit();
		expect( host.textContent ).toContain( 'Add meg a megnevezést.' );
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	test( 'a Befizetés shows the date, the method and the Közlemény, and no title', async () => {
		await render( ALL );
		await pick( typeRadios().find( ( r ) => r.value === 'payment' ) );
		expect( field( MONEY.dateField ) ).not.toBeNull();
		expect( radios( MONEY.methodField ).length ).toBe( 2 );
		expect( field( MONEY.noteField ) ).not.toBeNull();
		expect( field( MONEY.titleField ) ).toBeNull();
	} );
} );

describe( '„Azonnal befizetve” / „Azonnal visszafizetve”', () => {
	const checkbox = () => host.querySelector( 'input[type="checkbox"]' );

	test( 'the checkbox shows only for Díj and Kedvezmény, unticked, with its help', async () => {
		await render( ALL );
		expect( checkbox() ).toBeNull();
		await pick( typeRadios().find( ( r ) => r.value === 'payment' ) );
		expect( checkbox() ).toBeNull();
		await pick( typeRadios().find( ( r ) => r.value === 'charge' ) );
		expect( checkbox() ).not.toBeNull();
		expect( checkbox().checked ).toBe( false );
		expect( host.textContent ).toContain( 'Azonnal befizetve' );
		expect( host.textContent ).toContain( MONEY.completedChargeHelp );
		await pick( typeRadios().find( ( r ) => r.value === 'discount' ) );
		expect( host.textContent ).toContain( 'Azonnal visszafizetve' );
		expect( host.textContent ).toContain( MONEY.completedDiscountHelp );
		expect( MONEY.completedChargeHelp ).toBe( 'A díjjal együtt ugyanekkora befizetést is rögzít, így a tartozás nem változik.' );
		expect( MONEY.completedDiscountHelp ).toBe(
			'A kedvezménnyel együtt ugyanekkora visszafizetést is rögzít, így a tartozás nem változik.'
		);
	} );

	test( 'a holder who is not offered Díj or Kedvezmény never sees it', async () => {
		await render( MONEY_ONLY );
		await pick( typeRadios()[ 0 ] );
		expect( checkbox() ).toBeNull();
	} );

	test( 'ticking adds the method, the date and the Közlemény, prefilled with the title until it is edited', async () => {
		await render( ALL );
		await pick( typeRadios().find( ( r ) => r.value === 'discount' ) );
		await setValue( field( MONEY.titleField ), 'Elengedés' );
		await pick( checkbox() );
		expect( radios( MONEY.methodField ).length ).toBe( 2 );
		expect( field( MONEY.dateField ) ).not.toBeNull();
		expect( field( MONEY.noteField ).value ).toBe( 'Elengedés' );
		await setValue( field( MONEY.titleField ), 'Elengedés, kerekítés' );
		expect( field( MONEY.noteField ).value ).toBe( 'Elengedés, kerekítés' );
		await setValue( field( MONEY.noteField ), 'Átutalva' );
		await setValue( field( MONEY.titleField ), 'Más cím' );
		expect( field( MONEY.noteField ).value ).toBe( 'Átutalva' );
	} );

	test( 'the save button reads „2 tétel rögzítése” and the preview shows both lines, then the net', async () => {
		await render( ALL );
		await pick( typeRadios().find( ( r ) => r.value === 'discount' ) );
		await setValue( field( MONEY.amountField ), '5 000' );
		await setValue( field( MONEY.titleField ), 'Elengedés' );
		expect( host.querySelector( 'button[type="submit"]' ).textContent ).toBe( MONEY.save );
		await pick( checkbox() );
		expect( host.querySelector( 'button[type="submit"]' ).textContent ).toBe( '2 tétel rögzítése' );
		const preview = host.querySelector( 'dl.kg-bal' ).textContent;
		expect( preview ).toContain( 'Kedvezmény' + `−5${ NBSP }000${ NBSP }Ft` );
		expect( preview ).toContain( 'Visszafizetés' + `5${ NBSP }000${ NBSP }Ft` );
		expect( preview ).toContain( 'Tartozás mentés után' + `30${ NBSP }000${ NBSP }Ft` );
	} );

	test( 'a ticked Díj is one call with completed, the paid-side line\'s method, date and Közlemény', async () => {
		await render( ALL );
		await pick( typeRadios().find( ( r ) => r.value === 'charge' ) );
		await setValue( field( MONEY.amountField ), '2 000' );
		await setValue( field( MONEY.titleField ), 'Pótdíj' );
		await pick( checkbox() );
		await pick( radios( MONEY.methodField ).find( ( r ) => r.value === 'transfer' ) );
		await submit();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		const request = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( request.path ).toBe( '/wp-abilities/v1/abilities/kalaka/record-charge/run' );
		expect( request.data.input ).toMatchObject( {
			title: 'Pótdíj',
			completed: true,
			method: 'transfer',
			note: 'Pótdíj',
			amount: '2000',
		} );
		expect( request.data.input.value_date ).toMatch( /^\d{4}-\d{2}-\d{2}$/ );
		expect( done ).toHaveBeenCalledWith( 'Díj és befizetés rögzítve.' );
	} );

	test( 'a ticked box asks for the method', async () => {
		await render( ALL );
		await pick( typeRadios().find( ( r ) => r.value === 'charge' ) );
		await setValue( field( MONEY.amountField ), '2 000' );
		await setValue( field( MONEY.titleField ), 'Pótdíj' );
		await pick( checkbox() );
		await submit();
		expect( host.textContent ).toContain( MONEY.methodEmpty );
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	test( 'Közlemény follows the title whatever was typed in a Közlemény before (the A1 bug)', async () => {
		await render( ALL );
		// No type yet: the payment fields show; type something in „Közlemény”, then clear it.
		await setValue( field( MONEY.noteField ), 'Régi' );
		await setValue( field( MONEY.noteField ), '' );
		await pick( typeRadios().find( ( r ) => r.value === 'charge' ) );
		await setValue( field( MONEY.titleField ), 'Pótdíj' );
		await pick( checkbox() );
		expect( field( MONEY.noteField ).value ).toBe( 'Pótdíj' );
		await setValue( field( MONEY.titleField ), 'Pótdíj, késői' );
		expect( field( MONEY.noteField ).value ).toBe( 'Pótdíj, késői' );
	} );

	test( 'Közlemény typed under Befizetés does not decide the pair\'s Közlemény', async () => {
		await render( ALL );
		await pick( typeRadios().find( ( r ) => r.value === 'payment' ) );
		await setValue( field( MONEY.noteField ), 'Készpénz a helyszínen' );
		await pick( typeRadios().find( ( r ) => r.value === 'charge' ) );
		await setValue( field( MONEY.titleField ), 'Pótdíj' );
		await pick( checkbox() );
		expect( field( MONEY.noteField ).value ).toBe( 'Pótdíj' );
	} );

	test( 'the pair\'s Közlemény does not leak into a Befizetés, and the edited flag does not follow a type switch', async () => {
		await render( ALL );
		await pick( typeRadios().find( ( r ) => r.value === 'charge' ) );
		await setValue( field( MONEY.titleField ), 'Pótdíj' );
		await pick( checkbox() );
		await setValue( field( MONEY.noteField ), 'Átutalva' );
		await pick( typeRadios().find( ( r ) => r.value === 'payment' ) );
		expect( field( MONEY.noteField ).value ).toBe( '' );
		// Back to an owed-side type: the Közlemény is the title again, not the earlier edit.
		await pick( typeRadios().find( ( r ) => r.value === 'discount' ) );
		expect( field( MONEY.noteField ).value ).toBe( 'Pótdíj' );
		await setValue( field( MONEY.titleField ), 'Elengedés' );
		expect( field( MONEY.noteField ).value ).toBe( 'Elengedés' );
	} );

	test( 'errors of an earlier submit go when the type changes or the box is unticked (C7)', async () => {
		await render( ALL );
		await pick( typeRadios().find( ( r ) => r.value === 'charge' ) );
		await setValue( field( MONEY.amountField ), '2 000' );
		await setValue( field( MONEY.titleField ), 'Pótdíj' );
		await pick( checkbox() );
		await submit();
		expect( host.textContent ).toContain( MONEY.methodEmpty );
		await pick( checkbox() ); // unticked
		expect( host.textContent ).not.toContain( MONEY.methodEmpty );
		await pick( checkbox() ); // ticked again: no stale message before a new submit
		expect( host.textContent ).not.toContain( MONEY.methodEmpty );
		await submit();
		expect( host.textContent ).toContain( MONEY.methodEmpty );
		await pick( typeRadios().find( ( r ) => r.value === 'payment' ) );
		expect( host.textContent ).not.toContain( MONEY.methodEmpty );
	} );

	test( 'the title\'s hint stays visible while its message shows (C6)', async () => {
		await render( ALL );
		await pick( typeRadios().find( ( r ) => r.value === 'charge' ) );
		await setValue( field( MONEY.amountField ), '2 000' );
		await submit();
		expect( host.textContent ).toContain( MONEY.titleEmpty );
		expect( host.textContent ).toContain( MONEY.titleHint );
	} );
} );

describe( 'the note fields carry the participant-visible hint (topic 11, T36)', () => {
	const hints = () => host.textContent.split( MONEY.titleHint ).length - 1;
	const checkbox = () => host.querySelector( 'input[type="checkbox"]' );
	const noteHint = ( label ) => field( label ).closest( 'label' ).textContent;

	test( 'Közlemény of a Befizetés and a Visszafizetés', async () => {
		await render( ALL );
		await pick( typeRadios().find( ( r ) => r.value === 'payment' ) );
		expect( noteHint( MONEY.noteField ) ).toContain( MONEY.titleHint );
		await pick( typeRadios().find( ( r ) => r.value === 'refund' ) );
		expect( noteHint( MONEY.noteField ) ).toContain( MONEY.titleHint );
		expect( hints() ).toBe( 1 );
	} );

	test( 'Közlemény of the paid line of the „completed immediately” pair', async () => {
		await render( ALL );
		await pick( typeRadios().find( ( r ) => r.value === 'charge' ) );
		expect( field( MONEY.noteField ) ).toBeNull();
		expect( hints() ).toBe( 1 ); // the title's own
		await pick( checkbox() );
		expect( noteHint( MONEY.noteField ) ).toContain( MONEY.titleHint );
		expect( hints() ).toBe( 2 );
	} );

	test( 'Indoklás of the Korrekció, also while its message shows', async () => {
		await render( MONEY_ONLY, 'correction' );
		expect( noteHint( MONEY.reasonField ) ).toContain( MONEY.titleHint );
		await pick( radios( MONEY.directionField )[ 0 ] );
		await setValue( field( MONEY.amountField ), '500' );
		await submit();
		expect( noteHint( MONEY.reasonField ) ).toContain( MONEY.noteRequired );
		expect( noteHint( MONEY.reasonField ) ).toContain( MONEY.titleHint );
	} );
} );

describe( 'the preview: „Tartozás”', () => {
	const preview = () => host.querySelector( 'dl.kg-bal' ).textContent;

	test( 'it follows the type', async () => {
		await render( ALL );
		await setValue( field( MONEY.amountField ), '10 000' );
		expect( preview() ).toContain( 'Jelenlegi tartozás' + `30${ NBSP }000${ NBSP }Ft` );
		const after = async ( type ) => {
			await pick( typeRadios().find( ( r ) => r.value === type ) );
			return preview();
		};
		expect( await after( 'payment' ) ).toContain( 'Tartozás mentés után' + `20${ NBSP }000${ NBSP }Ft` );
		expect( await after( 'refund' ) ).toContain( 'Tartozás mentés után' + `40${ NBSP }000${ NBSP }Ft` );
		expect( await after( 'charge' ) ).toContain( 'Tartozás mentés után' + `40${ NBSP }000${ NBSP }Ft` );
		expect( await after( 'discount' ) ).toContain( 'Tartozás mentés után' + `20${ NBSP }000${ NBSP }Ft` );
	} );

	test( 'a negative value reads „(túlfizetés)”', async () => {
		await render( ALL );
		await pick( typeRadios().find( ( r ) => r.value === 'payment' ) );
		await setValue( field( MONEY.amountField ), '35 000' );
		expect( preview() ).toContain( `−5${ NBSP }000${ NBSP }Ft (túlfizetés)` );
	} );

	test( 'with an overpaid payer the current value reads „(túlfizetés)” too', async () => {
		await render( ALL, 'entry', {
			...money,
			payers: [ { payer: 'participant', label: 'Jelentkező', charged: '30000', paid: '35000', balance: '-5000' } ],
		} );
		expect( preview() ).toContain( 'Jelenlegi tartozás' + `−5${ NBSP }000${ NBSP }Ft (túlfizetés)` );
	} );
} );

describe( 'the Korrekció dialog', () => {
	test( 'its direction is „Befizetett összeg növelése” / „Befizetett összeg csökkentése”, with no default', async () => {
		await render( MONEY_ONLY, 'correction' );
		const dir = radios( MONEY.directionField );
		expect( dir.map( ( r ) => r.parentElement.textContent.trim() ) ).toEqual( [
			'Befizetett összeg növelése',
			'Befizetett összeg csökkentése',
		] );
		expect( dir.some( ( r ) => r.checked ) ).toBe( false );
		expect( radios( MONEY.typeField ) ).toEqual( [] );
		expect( radios( MONEY.methodField ) ).toEqual( [] );
		expect( field( MONEY.dateField ) ).toBeNull();
	} );

	test( 'a correction out raises „Tartozás” in the preview, one in lowers it; „Válassz irányt.” without one', async () => {
		await render( MONEY_ONLY, 'correction' );
		await setValue( field( MONEY.amountField ), '10 000' );
		await submit();
		expect( host.textContent ).toContain( 'Válassz irányt.' );
		await pick( radios( MONEY.directionField )[ 1 ] );
		expect( host.querySelector( 'dl.kg-bal' ).textContent ).toContain( 'Tartozás mentés után' + `40${ NBSP }000${ NBSP }Ft` );
		await pick( radios( MONEY.directionField )[ 0 ] );
		expect( host.querySelector( 'dl.kg-bal' ).textContent ).toContain( 'Tartozás mentés után' + `20${ NBSP }000${ NBSP }Ft` );
	} );

	test( 'it records through kalaka/record-correction with its reason', async () => {
		await render( MONEY_ONLY, 'correction' );
		await pick( radios( MONEY.directionField )[ 0 ] );
		await setValue( field( MONEY.amountField ), '500' );
		await setValue( field( MONEY.reasonField ), 'Téves összeg' );
		await submit();
		const request = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( request.path ).toBe( '/wp-abilities/v1/abilities/kalaka/record-correction/run' );
		expect( request.data.input ).toMatchObject( { direction: 'in', reason: 'Téves összeg' } );
		expect( done ).toHaveBeenCalledWith( 'Korrekció rögzítve.' );
	} );
} );

describe( 'the idempotency key (N22)', () => {
	const checkbox = () => host.querySelector( 'input[type="checkbox"]' );
	const keys = () => apiFetch.mock.calls.map( ( [ request ] ) => request.data.input.idempotency_key );

	// A save that fails keeps the dialog, so the same dialog can be sent again.
	beforeEach( () => {
		apiFetch.mockReset();
		apiFetch.mockRejectedValue( new Error( 'offline' ) );
	} );
	afterEach( () => {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( { id: 1 } );
	} );

	async function fillCharge() {
		await render( ALL );
		await pick( typeRadios().find( ( r ) => r.value === 'charge' ) );
		await setValue( field( MONEY.amountField ), '2 000' );
		await setValue( field( MONEY.titleField ), 'Pótdíj' );
	}

	test( 'a retry of the same values reuses the key', async () => {
		await fillCharge();
		await submit();
		await submit();
		expect( keys().length ).toBe( 2 );
		expect( keys()[ 0 ] ).toMatch( /^ui-[0-9a-f]{32}$/ );
		expect( keys()[ 1 ] ).toBe( keys()[ 0 ] );
	} );

	test( 'a changed type gives a new key', async () => {
		await fillCharge();
		await submit();
		await pick( typeRadios().find( ( r ) => r.value === 'discount' ) );
		await submit();
		expect( keys().length ).toBe( 2 );
		expect( keys()[ 1 ] ).not.toBe( keys()[ 0 ] );
		expect( apiFetch.mock.calls[ 1 ][ 0 ].path ).toContain( 'record-discount' );
	} );

	test( 'a ticked or unticked box gives a new key', async () => {
		await fillCharge();
		await submit();
		await pick( checkbox() );
		await pick( radios( MONEY.methodField ).find( ( r ) => r.value === 'cash' ) );
		await submit();
		expect( keys().length ).toBe( 2 );
		expect( keys()[ 1 ] ).not.toBe( keys()[ 0 ] );
		await pick( checkbox() ); // unticked again: the box is part of the entry, so this is another key
		await submit();
		expect( keys().length ).toBe( 3 );
		expect( keys()[ 2 ] ).not.toBe( keys()[ 1 ] );
	} );
} );
