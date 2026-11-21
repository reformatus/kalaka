/**
 * The grid's column filters and search (S37), as DataViews' own filters and as the server takes them. Pure:
 * no WordPress imports, so Jest runs it as it is. The server decides what a filter means
 * (`Admin\Grid\GridNarrowing`); this file only gives each column its DataViews filter and sends the filters
 * that narrow something.
 */
import { GRID } from './texts';

/**
 * „Nincs válasz” as one more value of a list column's own filter (a choice, a yes/no, a decision): the rows with
 * no value or no record. Never a stored value; the server knows it as `ColumnFilter::NO_ANSWER`.
 */
export const NO_ANSWER = 'kalaka:no-answer';

const NUMBER_OPERATORS = [ 'is', 'lessThan', 'greaterThan', 'between' ];

/** The filter-less columns' kinds are the columns' own `type`s (`Admin\Grid\GridModel`). */
const isList = ( column ) =>
	column.type === 'status' ||
	column.type === 'choice' ||
	( column.type === 'decision' && Object.keys( column.states || {} ).length > 0 );

/** The „Nincs válasz” element, for a list column that can be empty (`column.empty`, from the server). */
const noAnswer = ( column ) => ( column.empty ? [ { value: NO_ANSWER, label: GRID.unanswered } ] : [] );

/** The members a list column's filter offers: value and label, then „Nincs válasz” where the column can be empty. */
function elementsOf( column ) {
	const members =
		column.type === 'decision'
			? Object.entries( column.states ).map( ( [ value, label ] ) => ( { value, label } ) )
			: ( column.members || [] ).map( ( { value, label } ) => ( { value, label } ) );
	return [ ...members, ...noAnswer( column ) ];
}

/** A number as its chip shows it: Hungarian decimal comma, no padding (DataViews' own number format pads). */
const numberText = ( { item, field } ) => {
	const value = field.getValue( { item } );
	const number = Number( value );
	return value === null || value === undefined || value === '' || ! Number.isFinite( number )
		? ''
		: String( number ).replace( '.', ',' );
};

/**
 * The DataViews field props that give a column its filter, by the column's type. Every operator is
 * DataViews' own: a list is `isAny`/`isNone`, yes/no `is`/`isNot`, a number and Szám `is`/`lessThan`/
 * `greaterThan`/`between`, text (and Kód) `contains`, a date `before`/`after`.
 */
export function filterSpec( column ) {
	if ( isList( column ) ) {
		return { type: 'text', elements: elementsOf( column ), filterBy: { operators: [ 'isAny', 'isNone' ] } };
	}
	switch ( column.type ) {
		case 'consent': {
			const options = column.options || {};
			return {
				type: 'boolean',
				elements: [
					{ value: true, label: options[ '1' ] },
					{ value: false, label: options[ '0' ] },
					...noAnswer( column ),
				],
				filterBy: { operators: [ 'is', 'isNot' ] },
			};
		}
		case 'serial':
		case 'number':
			// Szám and the number columns take the same operators and decimals: DataViews' own number control,
			// with the chip in Hungarian form. [UNAPPROVED N54]
			return {
				type: 'number',
				format: { separatorThousand: '', separatorDecimal: ',', decimals: 2 },
				getValueFormatted: numberText,
				filterBy: { operators: NUMBER_OPERATORS },
			};
		case 'date':
			// The chip shows the date as the cell does; the site's own date format would be „F j, Y”.
			return { type: 'date', format: { date: 'Y. m. d.' }, filterBy: { operators: [ 'before', 'after' ] } };
		case 'time':
			// Display only: the server refuses to sort or filter a time column.
			return { type: 'text', filterBy: false, enableSorting: false };
		default:
			return { type: 'text', filterBy: { operators: [ 'contains' ] } };
	}
}

/** The pseudo column of the view's own condition: a locked filter, shown as DataViews' own chip. */
export const CONDITION = 'kg-condition';

/**
 * The view's own condition as a DataViews field with a locked filter on it (`isLocked` is DataViews' own: a chip
 * with no remove button that DataViews' reset keeps). The field's name is the condition's text, which is what the
 * chip shows while the filter has no value. Null for a view with no condition.
 */
export function conditionField( condition ) {
	if ( ! condition ) {
		return null;
	}
	return {
		id: CONDITION,
		label: condition.text,
		type: 'text',
		elements: [ { value: CONDITION, label: condition.text } ],
		filterBy: { operators: [ 'isAny' ] },
		getValue: () => '',
		render: () => null,
		enableSorting: false,
		enableHiding: false,
	};
}

/** The locked filter entry a view with a condition carries in its `filters`. */
export const lockedFilters = ( condition ) =>
	condition ? [ { field: CONDITION, operator: 'isAny', value: undefined, isLocked: true } ] : [];

/**
 * A typed number as the server takes it: a decimal string with a dot, or '' when it is no number. The decimal
 * comma and the dot both read as a decimal point; at most eight decimals, as the server keeps them.
 */
export function decimal( x ) {
	const text = typeof x === 'number' ? ( Number.isFinite( x ) ? x.toFixed( 8 ) : '' ) : String( x ?? '' ).trim().replace( ',', '.' );
	if ( ! /^-?(?:\d+\.?\d*|\.\d+)$/.test( text ) ) {
		return '';
	}
	const [ whole, fraction = '' ] = text.split( '.' );
	const kept = fraction.slice( 0, 8 ).replace( /0+$/, '' );
	const sign = whole.startsWith( '-' ) ? '-' : '';
	const digits = whole.replace( '-', '' ).replace( /^0+(?=\d)/, '' ) || '0';
	const out = sign + digits + ( kept ? '.' + kept : '' );
	return out === '-0' ? '0' : out;
}

/**
 * One DataViews filter as the server takes it, or null while it narrows nothing (a filter just added from
 * the header menu has no value yet). A number is a decimal (comma or dot); a range narrows only with both ends set.
 */
function filterOf( filter, column ) {
	let value = filter.value;
	if ( typeof value === 'string' ) {
		value = value.trim();
	}
	if ( column && ( column.type === 'number' || column.type === 'serial' ) ) {
		if ( filter.operator === 'between' ) {
			value = Array.isArray( value ) && value.length === 2 && value.every( ( x ) => decimal( x ) !== '' )
				? value.map( decimal )
				: '';
		} else {
			value = decimal( value );
		}
	}
	const empty =
		value === undefined || value === null || value === '' || ( Array.isArray( value ) && ! value.length );
	return empty ? null : { field: filter.field, operator: filter.operator, value };
}

/**
 * The filters that narrow and the trimmed search, as the REST takes them.
 *
 * @param {Object} view    DataViews' view state
 * @param {Array}  columns the tab's columns
 */
export function narrowOf( view, columns ) {
	const byId = Object.fromEntries( columns.map( ( c ) => [ c.column, c ] ) );
	const filters = ( view.filters || [] )
		.map( ( f ) => filterOf( f, byId[ f.field ] ) )
		.filter( Boolean );
	return { filters, search: ( view.search || '' ).trim() };
}

/** Whether a filter or a search narrows the view. */
export const isNarrowed = ( narrow ) => narrow.filters.length > 0 || narrow.search !== '';

/** The columns a filter narrows, for the header shade. */
export const filteredIds = ( narrow ) => new Set( narrow.filters.map( ( f ) => f.field ) );
