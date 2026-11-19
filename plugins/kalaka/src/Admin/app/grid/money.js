/**
 * The detail panel's pure money and date logic (S38): amounts in fillér as whole numbers, „30 000 Ft”, the
 * balance in words, the site time zone's day, and the recording dialog's checks. No WordPress imports, so Jest
 * runs it as it is. [UNAPPROVED N5] [UNAPPROVED N6] [UNAPPROVED N7] [UNAPPROVED N8]
 */
import { MONEY } from './texts';

const NBSP = ' ';

/**
 * An amount as the read gives it (`"60000.00000000"`, signed) in fillér, a whole number: the two decimals
 * HUF has, the rest rounded. Null for anything that is not a decimal number.
 */
export function toMinor( value ) {
	const m = /^(-)?(\d+)(?:\.(\d*))?$/.exec( String( value ).trim() );
	if ( ! m ) {
		return null;
	}
	const fraction = ( m[ 3 ] || '' ).padEnd( 3, '0' );
	let minor = parseInt( m[ 2 ], 10 ) * 100 + parseInt( fraction.slice( 0, 2 ), 10 );
	if ( parseInt( fraction[ 2 ], 10 ) >= 5 ) {
		minor += 1;
	}
	return m[ 1 ] ? -minor : minor;
}

/** „30 000 Ft”, with no-break spaces, a decimal comma only when there are fillér („1 000,50 Ft”) and a real minus. */
export function formatMinor( minor ) {
	const sign = minor < 0 ? '−' : '';
	const abs = Math.abs( minor );
	const whole = String( Math.floor( abs / 100 ) ).replace( /\B(?=(\d{3})+(?!\d))/g, NBSP );
	const fil = abs % 100;
	return sign + whole + ( fil ? ',' + String( fil ).padStart( 2, '0' ) : '' ) + NBSP + 'Ft';
}

/** An amount of the read as „30 000 Ft”. */
export const formatMoney = ( value ) => formatMinor( toMinor( value ) ?? 0 );

/** The absolute amount of a signed value: the type word gives the direction. */
export const formatAbsolute = ( value ) => formatMinor( Math.abs( toMinor( value ) ?? 0 ) );

/** The balance in words: above zero „… hátralék”, below zero „… túlfizetés”, zero „0 Ft”. [UNAPPROVED T5] */
export function balanceText( minor ) {
	if ( minor > 0 ) {
		return formatMinor( minor ) + ' ' + MONEY.due;
	}
	return minor < 0 ? formatMinor( -minor ) + ' ' + MONEY.over : formatMinor( 0 );
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
 * The balance a payer has after the entry: a payment lowers it, a refund raises it (above zero is due).
 * Null while there is no valid amount.
 */
export function balanceAfter( before, minor, refund ) {
	if ( before === null || minor === null || minor <= 0 ) {
		return null;
	}
	return refund ? before + minor : before - minor;
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
 * (`payer`, `amount`, `value_date`, `method`, `note`), empty when the entry may be sent.
 *
 * @param {Object}  entry { several, payer, amount, date, method, note, refund }
 * @param {string}  day   today as `YYYY-MM-DD`
 */
export function validateEntry( entry, day ) {
	const errors = {};
	if ( entry.several && ! entry.payer ) {
		errors.payer = MONEY.payerEmpty;
	}
	const minor = parseAmount( entry.amount );
	if ( String( entry.amount ).trim() === '' ) {
		errors.amount = MONEY.amountEmpty;
	} else if ( minor === null || minor <= 0 ) {
		errors.amount = MONEY.amountBad;
	}
	if ( ! entry.date ) {
		errors.value_date = MONEY.dateEmpty;
	} else if ( entry.date > day ) {
		errors.value_date = MONEY.dateFuture;
	}
	if ( ! entry.method ) {
		errors.method = MONEY.methodEmpty;
	}
	if ( entry.refund && String( entry.note ).trim() === '' ) {
		errors.note = MONEY.noteRequired;
	}
	return errors;
}

/**
 * The methods a dialog offers. A refund or a correction is returned by transfer or cash only (settled): the
 * read's `refund_methods` when it carries them, else the payment methods without the card terminal. A payment
 * may be by any of `methods`.
 */
export function methodsFor( money, refund ) {
	if ( ! refund ) {
		return money.methods;
	}
	return Array.isArray( money.refund_methods ) ? money.refund_methods : money.methods.filter( ( m ) => m.value !== 'card_terminal' );
}
