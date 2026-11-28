/**
 * The grid's pure logic (S37): cell text, the sum row's text and the table copy. No WordPress imports, so
 * Jest runs it as it is.
 */
import { SUM, SUM_AVERAGE } from './texts';

/** Fills `%d`, `%s` and `%1$s`-style placeholders in order or by position. */
export function fmt( text, ...args ) {
	let i = 0;
	return text.replace( /%(?:(\d)\$)?([ds])/g, ( match, position ) =>
		String( position ? args[ parseInt( position, 10 ) - 1 ] : args[ i++ ] )
	);
}

/**
 * A registration as the screens name it: its code, and its name beside it when the read gives one. A null or empty
 * name (the viewer's view has no access to the name) leaves the code alone.
 */
export const codeName = ( code, name ) => ( name ? `${ code } · ${ name }` : String( code ) );

/** A decimal string or number in Hungarian form: a decimal comma, no grouping. */
export function number( value ) {
	return String( value ).replace( '.', ',' );
}

/** An average rounded to one decimal, in Hungarian form. */
export function oneDecimal( value ) {
	return number( Number( value ).toFixed( 1 ) );
}

/** A stored day `YYYY-MM-DD` as `YYYY. MM. DD.`; anything else as it is. */
export function day( value ) {
	const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec( String( value ) );
	return m ? `${ m[ 1 ] }. ${ m[ 2 ] }. ${ m[ 3 ] }.` : String( value );
}

/** The labels of a stored choice: one value, or a JSON list of them for a multiple choice. */
function choice( column, value ) {
	const options = column.options || {};
	if ( value.startsWith( '[' ) ) {
		try {
			const list = JSON.parse( value );
			if ( Array.isArray( list ) ) {
				return list.map( ( v ) => options[ v ] ?? String( v ) ).join( ', ' );
			}
		} catch {
			// Not a list: shown as stored.
		}
	}
	return options[ value ] ?? value;
}

/** A cell's text, as shown and as copied. */
export function cellText( column, row ) {
	const value = row.values[ column.column ];
	if ( value === null || value === undefined || value === '' ) {
		// A column with an N/A text (the awaits column) shows it for no value: N/A is not „Nem”.
		return column.na ?? '';
	}
	switch ( column.type ) {
		case 'status':
		case 'consent':
			return ( column.options || {} )[ value ] ?? String( value );
		case 'decision':
			return ( column.states || {} )[ value ] ?? String( value );
		case 'choice':
			return choice( column, String( value ) );
		case 'date':
			return day( value );
		case 'number':
			return number( value );
		default:
			return String( value );
	}
}

/**
 * The sum of one column: `{ shown, full }`, the visible number and the full text for screen readers and the
 * tooltip; `full` already names the column for `members`, otherwise the caller puts the column first.
 * Null when the column has no sum.
 */
export function sumParts( column, sums ) {
	const s = sums && sums[ column.column ];
	if ( ! s || s.n === null || s.n === undefined ) {
		return null;
	}
	switch ( s.kind ) {
		case 'members':
			return {
				shown: String( s.n ),
				full: fmt( SUM.members, column.label, ( s.labels || s.members ).join( ', ' ), s.n ),
				named: true,
			};
		case 'rows':
			return { shown: String( s.n ), full: fmt( SUM.rows, s.n ) };
		case 'filled':
			return { shown: String( s.n ), full: fmt( SUM.filled, s.n ) };
		case 'sum':
			return { shown: number( s.n ), full: fmt( SUM.total, number( s.n ) ) };
		case 'average':
			return { shown: oneDecimal( s.n ), full: fmt( SUM_AVERAGE, oneDecimal( s.n ) ) };
		case 'count':
			return { shown: String( s.n ), full: fmt( SUM.count, s.member, s.n ) };
		default:
			return null;
	}
}

const escape = ( s ) =>
	String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );

/** A text cell for Excel (`mso-number-format`) and LibreOffice Calc (`sdnum`; Calc ignores `mso-*`). */
const TEXT = ' style="mso-number-format:\'\\@\'" sdnum="1038;0;@"';

/** A day as a real date for Calc (`sdval`: days since 1899-12-30); Excel parses the shown text by its locale. */
function dateAttributes( value ) {
	const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec( String( value ?? '' ) );
	if ( ! m ) {
		return TEXT;
	}
	const days = Math.round(
		( Date.UTC( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ] ) - Date.UTC( 1899, 11, 30 ) ) / 86400000
	);
	return ' sdval="' + days + '" sdnum="1033;0;YYYY-MM-DD"';
}

/** The attributes of one copied cell: numbers keep their type, dates go to Calc as dates, the rest is text. */
function cellAttributes( column, row, text ) {
	if ( column.numeric ) {
		const raw = row.values[ column.column ];
		if ( text === '' ) {
			return '';
		}
		const format = /^-?\d+$/.test( String( raw ) ) ? '1038;0;0' : '1033;0;General';
		return ' sdval="' + escape( raw ) + '" sdnum="' + format + '"';
	}
	if ( column.type === 'date' ) {
		return dateAttributes( row.values[ column.column ] );
	}
	return TEXT;
}

/** The view as an HTML table: the header row, then a row per registration. */
export function tableHtml( columns, rows ) {
	const head =
		'<tr>' + columns.map( ( c ) => '<th' + TEXT + '>' + escape( c.label ) + '</th>' ).join( '' ) + '</tr>';
	const body = rows
		.map(
			( r ) =>
				'<tr>' +
				columns
					.map( ( c ) => {
						const text = cellText( c, r );
						return '<td' + cellAttributes( c, r, text ) + '>' + escape( text ) + '</td>';
					} )
					.join( '' ) +
				'</tr>'
		)
		.join( '' );
	return '<table>' + head + body + '</table>';
}

/** The view as tab-separated text: no quoting, a tab or line break inside a cell becomes a space. */
export function plainText( columns, rows ) {
	const clean = ( s ) => String( s ).replace( /[\t\r\n]+/g, ' ' );
	return [ columns.map( ( c ) => clean( c.label ) ).join( '\t' ) ]
		.concat( rows.map( ( r ) => columns.map( ( c ) => clean( cellText( c, r ) ) ).join( '\t' ) ) )
		.join( '\n' );
}
