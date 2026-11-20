/**
 * The detail panel's pure money and date logic (S38): amounts in fillér as whole numbers, „30 000 Ft” and
 * „−5 000 Ft”, the site time zone's day, and the recording dialogs' checks. Every amount that has a direction is
 * signed: positive is money in, a real minus money out (topic 6a G11). No WordPress imports, so Jest runs it as
 * it is. [UNAPPROVED N42] [UNAPPROVED N8]
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
 * An amount of the read as „30 000 Ft”, signed: a minus is money out. The balance follows the same sign:
 * positive is still owed, negative is in the payer's favour. [UNAPPROVED N6] [UNAPPROVED N42]
 */
export const formatMoney = ( value, currency ) => formatMinor( toMinor( value ) ?? 0, currency );

/**
 * A line's stored amount as the panel shows it, signed the same way as the payers' `paid`: a payment fact is
 * positive when money came in and negative when it went out (the ledger stores it the other way round), and a
 * charge or an adjustment is shown as stored, a charge positive like „Fizetendő”. [UNAPPROVED N5] [UNAPPROVED N42]
 */
const PAYMENT_FACTS = new Set( [ 'payment', 'manual_payment', 'refund', 'manual_refund' ] );
export const formatEntry = ( value, kind, currency ) =>
	formatMinor( PAYMENT_FACTS.has( kind ) ? -( toMinor( value ) ?? 0 ) : toMinor( value ) ?? 0, currency );

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
 * The balance a payer has after the entry: a payment (or a correction in) lowers it, a refund (or a correction
 * out) raises it (above zero is due). Null while there is no valid amount.
 */
export function balanceAfter( before, minor, raises ) {
	if ( before === null || minor === null || minor <= 0 ) {
		return null;
	}
	return raises ? before + minor : before - minor;
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
 * (`payer`, `amount`, `value_date`, `method`, `direction`, `note`), empty when the entry may be sent. A
 * correction has no method and no date, but a direction and a required reason; a payment and a refund have a
 * method and a date and an optional note. [UNAPPROVED N15]
 *
 * @param {Object}  entry { several, payer, amount, date, method, note, kind, direction }, `kind` being
 *                        `payment`, `refund` or `correction`
 * @param {string}  day   today as `YYYY-MM-DD`
 */
export function validateEntry( entry, day ) {
	const errors = {};
	const correction = entry.kind === 'correction';
	if ( entry.several && ! entry.payer ) {
		errors.payer = MONEY.payerEmpty;
	}
	// Both dialogs have a direction and neither defaults it: a payment or a refund, or a correction in or out.
	if ( ! entry.direction ) {
		errors.direction = MONEY.directionEmpty;
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
