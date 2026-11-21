/**
 * The detail panel's pure money and date logic (S38): amounts in fillér as whole numbers, „30 000 Ft” and
 * „−5 000 Ft”, the site time zone's day, and the recording dialogs' checks. Every line is on one of two sides
 * (topic 10): owed-side lines show in „Fizetendő”, paid-side lines in „Befizetett”, and in each column a
 * positive amount means what the column's name says and a real minus the opposite; no „+” is ever shown.
 * „Tartozás” is Fizetendő − Befizetett. No WordPress imports, so Jest runs it as it is. [UNAPPROVED N5]
 * [UNAPPROVED N42] [UNAPPROVED N8]
 */
import { MONEY } from './texts';

const NBSP = ' ';

/**
 * An amount as the read gives it (`"60000.00000000"`, signed) in minor units, a whole number: the two decimals
 * HUF has, the rest dropped toward zero, as `Money` does (below one minor unit counts as zero). Null for
 * anything that is not a decimal number.
 */
export function toMinor( value ) {
	const m = /^(-)?(\d+)(?:\.(\d*))?$/.exec( String( value ).trim() );
	if ( ! m ) {
		return null;
	}
	const fraction = ( m[ 3 ] || '' ).padEnd( 2, '0' );
	const minor = parseInt( m[ 2 ], 10 ) * 100 + parseInt( fraction.slice( 0, 2 ), 10 );
	return m[ 1 ] && minor !== 0 ? -minor : minor;
}

/** The currency as shown: „Ft” for HUF, any other currency by its code. */
const UNITS = { HUF: 'Ft' };
export const unitOf = ( currency ) => UNITS[ currency || 'HUF' ] || currency;

/** „30 000 Ft”, with no-break spaces, a decimal comma only when there are minor units („1 000,50 Ft”) and a real minus. */
export function formatMinor( minor, currency ) {
	const sign = minor < 0 ? '−' : '';
	const abs = Math.abs( minor );
	const whole = String( Math.floor( abs / 100 ) ).replace( /\B(?=(\d{3})+(?!\d))/g, NBSP );
	const fil = abs % 100;
	return sign + whole + ( fil ? ',' + String( fil ).padStart( 2, '0' ) : '' ) + NBSP + unitOf( currency );
}

/**
 * An amount of the read as „30 000 Ft”, signed: a minus means the opposite of the column's name („Fizetendő”: a
 * discount; „Befizetett”: money paid back). [UNAPPROVED N42]
 */
export const formatMoney = ( value, currency ) => formatMinor( toMinor( value ) ?? 0, currency );

/**
 * „Tartozás” in minor units as the panel shows it: positive is still owed, zero is settled, and a negative value
 * is „−5 000 Ft (túlfizetés)”, the word lowercase in brackets (owner, topic 10). Only the „Tartozás” cells and
 * the dialogs' preview use it, never „Fizetendő” or „Befizetett”. [UNAPPROVED N6] [UNAPPROVED N49]
 */
export const formatBalanceMinor = ( minor, currency ) =>
	formatMinor( minor, currency ) + ( minor < 0 ? ` (${ MONEY.overpaid })` : '' );

/** „Tartozás” as the read gives it (`balance`, signed); the read's figure is shown, never recomputed. */
export const formatBalance = ( value, currency ) => formatBalanceMinor( toMinor( value ) ?? 0, currency );

/*
 * SEAM (Backend #209, design note §9): everything the panel has to know about the server's money lines sits in
 * this block, so Backend's rework changes one place. #209's head is not pushed yet, so the panel is built
 * against today's server and tested with stubs. The new read gives each line `type` (`fee`, `manual_charge`,
 * `manual_discount`, `component`, `payment`, `refund`, `manual_payment`, `manual_refund`), `side` (`owed` or
 * `paid`) and `title` (set on manual rows only), and `can.record_money` replaces `can.record_payment`,
 * `can.record_refund` and `can.record_correction`. Until it lands today's read has `kind` (and `kind_label`) and
 * the three old flags, and the panel falls back to them. [UNAPPROVED N44] [UNAPPROVED N47] [UNAPPROVED N51]
 */

/** The kinds on the paid side: money that moved. Every other kind (the fee, a manual charge or discount, a named
 * component, and later the support lines) is owed-side, shown as stored. Used only while the read has no `side`.
 * [UNAPPROVED N51] */
const PAID_SIDE = new Set( [ 'payment', 'manual_payment', 'refund', 'manual_refund' ] );
export const sideOf = ( kind ) => ( PAID_SIDE.has( kind ) ? 'paid' : 'owed' );

/** A line's side: the read's own `side`, else (today's server) the side of its kind. */
export const lineSide = ( line ) => line.side || sideOf( line.kind );

/**
 * A stored amount as the panel shows it, in minor units: an owed-side line as stored (a charge positive, a
 * discount negative), a paid-side line flipped from the ledger's stored sign (payments are stored negative, refunds
 * positive) so that money in is positive and money paid back negative. A correction is a payment fact too: it is
 * positive when it raises „Befizetett” and negative when it lowers it. [UNAPPROVED N5] [UNAPPROVED N42]
 */
const shownMinor = ( value, side ) => ( side === 'paid' ? -( toMinor( value ) ?? 0 ) : toMinor( value ) ?? 0 );

export const formatEntry = ( value, kind, currency ) => formatMinor( shownMinor( value, sideOf( kind ) ), currency );

/** A line's two amount cells: the amount in its own side's column, the other left empty (""). */
export function entryColumns( line, currency ) {
	const side = lineSide( line );
	const shown = formatMinor( shownMinor( line.amount, side ), currency );
	return side === 'paid' ? { owed: '', paid: shown } : { owed: shown, paid: '' };
}

/** The type word of a line: the read's own label (a named component under its own name), and for the fee and the
 * two manual owed-side types the owner's words when the read gives none. [UNAPPROVED T8] */
export function typeWord( line ) {
	const own = line.kind_label || '';
	if ( own ) {
		return own;
	}
	return (
		{ fee: MONEY.entryCharge, manual_charge: MONEY.manualCharge, manual_discount: MONEY.manualDiscount }[
			line.type || line.kind
		] || ''
	);
}

/** The „Megjegyzés” cell: a manual charge or discount shows its participant-visible `title`, the others their
 * note. [UNAPPROVED N47] */
export const lineNote = ( line ) => line.title || line.note || '';

/**
 * The types the „Tétel rögzítése” dialog offers, in the fixed order. The one money-recording permission
 * (`can.record_money`) offers all four, but Díj and Kedvezmény only while the registration is `registered`
 * (Backend boxes that for the owner). TEMPORARY, until #209 lands: today's server sends `can.record_payment`
 * and `can.record_refund`, which offer Befizetés and Visszafizetés only. [UNAPPROVED N44] [UNAPPROVED N54]
 *
 * @param {Object} can    the read's `can`
 * @param {string} status the registration's status (`registered`, …)
 */
export function allowedTypes( can, status ) {
	const c = can || {};
	const owed = !! c.record_money && status === 'registered';
	return [
		[ 'payment', c.record_money || c.record_payment ],
		[ 'refund', c.record_money || c.record_refund ],
		[ 'charge', owed ],
		[ 'discount', owed ],
	]
		.filter( ( [ , on ] ) => !! on )
		.map( ( [ type ] ) => type );
}

/** Whether the „Korrekció rögzítése” button is offered: the money-recording permission, or (temporary, today's
 * server) its own old flag. */
export const canCorrect = ( can ) => !! ( can && ( can.record_money || can.record_correction ) );

/** The ability each kind of entry runs. */
export const ABILITIES = {
	payment: 'kalaka/record-payment',
	refund: 'kalaka/record-refund',
	correction: 'kalaka/record-correction',
	// SEAM (Backend #209): `{ event_id, code, payer?, amount, currency, title, idempotency_key, completed?, method?,
	// value_date?, note? }`; `method` and `value_date` exactly when `completed`.
	charge: 'kalaka/record-charge',
	discount: 'kalaka/record-discount',
};

/**
 * The ability and its input for one entry, with `can` and the registration's status checked first: a type the
 * read does not allow is never called. `completed` makes the call also record the matching paid-side line (a
 * Befizetés for a Díj, a Visszafizetés for a Kedvezmény), its method, value date and Közlemény (`note`). The two
 * rows have no link. [UNAPPROVED N45]
 *
 * @param {number} event  the event's ID
 * @param {Object} entry  { kind, code, currency, payer, amount, valueDate, method, note, direction, title,
 *                        completed, key }; with `completed`, `note` is the paid-side line's „Közlemény”
 * @param {Object} can    the read's `can`
 * @param {string} status the registration's status
 */
export function buildCall( event, entry, can, status ) {
	const { kind } = entry;
	const allowed = kind === 'correction' ? canCorrect( can ) : allowedTypes( can, status ).includes( kind );
	if ( ! allowed ) {
		throw new Error( 'not allowed: ' + kind );
	}
	const input = {
		event_id: event,
		code: entry.code,
		payer: entry.payer,
		amount: entry.amount,
		currency: entry.currency,
		idempotency_key: entry.key,
	};
	if ( kind === 'correction' ) {
		input.direction = entry.direction;
		input.reason = entry.note;
	} else if ( kind === 'charge' || kind === 'discount' ) {
		input.title = entry.title;
		if ( entry.completed ) {
			input.completed = true;
			input.method = entry.method;
			input.value_date = entry.valueDate;
			if ( entry.note ) {
				input.note = entry.note; // the paid-side line's „Közlemény”, prefilled with the title
			}
		}
	} else {
		input.method = entry.method;
		input.value_date = entry.valueDate;
		if ( entry.note ) {
			input.note = entry.note;
		}
	}
	return { ability: ABILITIES[ kind ], input };
}

/** The snackbar text for a saved entry. [UNAPPROVED T29] */
export function savedText( kind, completed ) {
	if ( completed && kind === 'charge' ) {
		return MONEY.savedChargePaid;
	}
	if ( completed && kind === 'discount' ) {
		return MONEY.savedDiscountRefund;
	}
	return {
		payment: MONEY.saved,
		refund: MONEY.savedRefund,
		charge: MONEY.savedCharge,
		discount: MONEY.savedDiscount,
		correction: MONEY.savedCorrection,
	}[ kind ];
}

/**
 * What a person typed as an amount, in fillér: spaces (also no-break) as thousands separators, a comma or a dot
 * as the decimal sign, at most two decimals. Null when it is not such a number; zero is a number.
 */
export function parseAmount( raw ) {
	const s = String( raw ).replace( /[\s\u00a0\u202f]/g, '' ).replace( ',', '.' );
	return /^\d+(\.\d{1,2})?$/.test( s ) ? toMinor( s ) : null;
}

/** The amount in fillér as the ability takes it: `"30000"`, or `"1000.50"` with fillér. */
export function amountString( minor ) {
	const fil = minor % 100;
	return String( Math.floor( minor / 100 ) ) + ( fil ? '.' + String( fil ).padStart( 2, '0' ) : '' );
}

/**
 * „Tartozás” after the entry: a payment or a discount (and a correction that raises „Befizetett”) lowers it, a
 * refund or a charge (and a correction that lowers „Befizetett”) raises it. Null while there is no valid amount.
 */
export function balanceAfter( before, minor, raises ) {
	if ( before === null || minor === null || minor <= 0 ) {
		return null;
	}
	return raises ? before + minor : before - minor;
}

/** Whether the entry raises „Tartozás”: a refund, a charge, or a correction that lowers „Befizetett”. */
export const raisesDebt = ( kind, direction ) =>
	kind === 'refund' || kind === 'charge' || ( kind === 'correction' && direction === 'out' );

/**
 * The preview's „Tartozás mentés után” in minor units, or null while the type (or a correction's direction) or a
 * valid amount is missing. A charge or discount saved „completed immediately” is paired with a payment or a
 * refund of the same amount, so the net is no change; the preview shows the two lines separately (`pairPreview`)
 * and this net. [UNAPPROVED N46]
 */
export function previewAfter( { before, minor, kind, direction, completed } ) {
	if ( ! kind || ( kind === 'correction' && ! direction ) ) {
		return null;
	}
	const after = balanceAfter( before, minor, raisesDebt( kind, direction ) );
	return after !== null && completed && ( kind === 'charge' || kind === 'discount' ) ? before : after;
}

/**
 * The two lines a „completed immediately” entry records, each with its effect on „Tartozás” in minor units (a
 * charge or a refund raises it, a discount or a payment lowers it): the owed-side line, then the paid-side one.
 * Empty without a valid amount. [UNAPPROVED N46]
 */
export function pairPreview( { kind, minor } ) {
	if ( ( kind !== 'charge' && kind !== 'discount' ) || minor === null || minor <= 0 ) {
		return [];
	}
	return kind === 'charge'
		? [
				{ kind: 'charge', effect: minor },
				{ kind: 'payment', effect: -minor },
		  ]
		: [
				{ kind: 'discount', effect: -minor },
				{ kind: 'refund', effect: minor },
		  ];
}

/** The offset a time zone name like `+01:00` stands for, in minutes; null for a name. */
function offsetOf( zone ) {
	const m = /^([+-])(\d{2}):?(\d{2})$/.exec( zone );
	return m ? ( m[ 1 ] === '-' ? -1 : 1 ) * ( parseInt( m[ 2 ], 10 ) * 60 + parseInt( m[ 3 ], 10 ) ) : null;
}

/** The calendar day of an instant in the site time zone (a name, or an offset like `+01:00`): `{ y, m, d }`. */
function dayParts( instant, zone ) {
	const date = instant instanceof Date ? instant : new Date( instant );
	const offset = offsetOf( zone || 'UTC' );
	const parts = new Intl.DateTimeFormat( 'en-CA', {
		timeZone: offset === null ? zone || 'UTC' : 'UTC',
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
	} ).formatToParts( offset === null ? date : new Date( date.getTime() + offset * 60000 ) );
	const get = ( type ) => parts.find( ( p ) => p.type === type ).value;
	return { y: get( 'year' ), m: get( 'month' ), d: get( 'day' ) };
}

/** An instant's day in the site time zone as `2026. 12. 05.`; empty for no instant. */
export function formatDay( instant, zone ) {
	if ( ! instant ) {
		return '';
	}
	const { y, m, d } = dayParts( instant, zone );
	return `${ y }. ${ m }. ${ d }.`;
}

/** Today in the site time zone as `YYYY-MM-DD`, the form a date input holds. */
export function today( zone, now = new Date() ) {
	const { y, m, d } = dayParts( now, zone );
	return `${ y }-${ m }-${ d }`;
}

/**
 * The recording dialog's checks, the server's rules ahead of the request: an object of messages by field
 * (`payer`, `type`, `direction`, `amount`, `title`, `value_date`, `method`, `note`), empty when the entry may be
 * sent. The „Tétel rögzítése” dialog has a type (`kind`: `payment`, `refund`, `charge` or `discount`, empty until
 * picked); the correction a direction and a required reason, no method and no date. A payment and a refund
 * have a method and a date and an optional note; a charge and a discount a required title (the participant sees
 * it) and no method or date, unless they are saved „completed immediately”, which adds the paid-side line's
 * method and date. [UNAPPROVED N15] [UNAPPROVED N44] [UNAPPROVED N45] [UNAPPROVED N48]
 *
 * @param {Object}  entry { several, payer, amount, date, method, note, title, kind, direction, completed }
 * @param {string}  day   today as `YYYY-MM-DD`
 */
export function validateEntry( entry, day ) {
	const errors = {};
	const { kind } = entry;
	const correction = kind === 'correction';
	const owedSide = kind === 'charge' || kind === 'discount';
	if ( entry.several && ! entry.payer ) {
		errors.payer = MONEY.payerEmpty;
	}
	// Neither dialog defaults its choice: the entry's type, or the correction's direction.
	if ( correction ? ! entry.direction : ! kind ) {
		errors[ correction ? 'direction' : 'type' ] = correction ? MONEY.directionEmpty : MONEY.typeEmpty;
	}
	const minor = parseAmount( entry.amount );
	if ( String( entry.amount ).trim() === '' ) {
		errors.amount = MONEY.amountEmpty;
	} else if ( minor === null || minor <= 0 ) {
		errors.amount = MONEY.amountBad;
	}
	if ( correction ) {
		if ( String( entry.note ).trim() === '' ) {
			errors.note = MONEY.noteRequired;
		}
		return errors;
	}
	if ( owedSide ) {
		// „Completed immediately” adds the paid-side line's method and date; its „Közlemény” is optional.
		if ( String( entry.title ?? '' ).trim() === '' ) {
			errors.title = MONEY.titleEmpty;
		}
		if ( ! entry.completed ) {
			return errors;
		}
	}
	if ( ! entry.date ) {
		errors.value_date = MONEY.dateEmpty;
	} else if ( entry.date > day ) {
		errors.value_date = MONEY.dateFuture;
	}
	if ( ! entry.method ) {
		errors.method = MONEY.methodEmpty;
	}
	return errors;
}
