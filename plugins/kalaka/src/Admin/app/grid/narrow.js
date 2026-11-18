/**
 * The grid's column filters and search (S37), as DataViews' own filters and as the server takes them. Pure:
 * no WordPress imports, so Jest runs it as it is. The server decides what a filter means
 * (`Admin\Grid\GridNarrowing`); this file only gives each column its DataViews filter and sends the filters
 * that narrow something.
 */
import { GRID } from './texts';

/** The pseudo column of „Nincs válasz”: a filter on the answers a row lacks, not a column of the table. */
export const UNANSWERED = 'unanswered';

const NUMBER_OPERATORS = [ 'is', 'lessThan', 'greaterThan', 'between' ];

/** The filter-less columns' kinds are the columns' own `type`s (`Admin\Grid\GridModel`). */
const isList = ( column ) =>
	column.type === 'status' ||
	column.type === 'choice' ||
	( column.type === 'decision' && Object.keys( column.states || {} ).length > 0 );

/** The members a list column's filter offers: value and label. */
function elementsOf( column ) {
	if ( column.type === 'decision' ) {
		return Object.entries( column.states ).map( ( [ value, label ] ) => ( { value, label } ) );
	}
	return ( column.members || [] ).map( ( { value, label } ) => ( { value, label } ) );
}

/**
 * The DataViews field props that give a column its filter, by the column's type. Every operator is
 * DataViews' own: a list is `isAny`/`isNone`, yes/no `is`/`isNot`, a number `is`/`lessThan`/`greaterThan`/
 * `between`, text (and Kód) `contains`, a date `before`/`after`, Sorszám `between`.
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
				],
				filterBy: { operators: [ 'is', 'isNot' ] },
			};
		}
		case 'serial':
			return { type: 'integer', filterBy: { operators: [ 'between' ] } };
		case 'number':
			return { type: 'integer', filterBy: { operators: NUMBER_OPERATORS } };
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

/** The columns „Nincs válasz” can name: a participant field or a decision. */
export const answerable = ( columns ) =>
	columns.filter( ( c ) => c.column.startsWith( 'field:' ) || c.column.startsWith( 'decision:' ) );

/**
 * The virtual DataViews field of „Nincs válasz”: no operator of DataViews' is „empty”, so it is one more
 * filter whose members are the columns, and the filter holds the rows that have no answer in at least one of them.
 * It is not a column of the table (the view's `fields` do not list it).
 */
export function unansweredField( columns ) {
	const members = answerable( columns );
	if ( ! members.length ) {
		return null;
	}
	return {
		id: UNANSWERED,
		label: GRID.unanswered,
		type: 'text',
		elements: members.map( ( c ) => ( { value: c.column, label: c.label } ) ),
		// A plain option under „Szűrő hozzáadása”, like the other filters, not pinned: no chip until the user
		// adds it. [UNAPPROVED N10] The owner's ruling of 2026-11-18.
		filterBy: { operators: [ 'isAny' ] },
		getValue: () => '',
		render: () => null,
		enableSorting: false,
		enableHiding: false,
	};
}

const whole = ( x ) => ( Number.isFinite( parseFloat( x ) ) ? Math.trunc( parseFloat( x ) ) : '' );

/**
 * One DataViews filter as the server takes it, or null while it narrows nothing (a filter just added from
 * the header menu has no value yet). A number is cut to the whole number its chip shows, so the chip and the
 * rows agree; a range narrows only with both ends set.
 */
function filterOf( filter, column ) {
	let value = filter.value;
	if ( typeof value === 'string' ) {
		value = value.trim();
	}
	if ( filter.field === UNANSWERED ) {
		// no column to look at: the value is the list of columns
	} else if ( column && ( column.type === 'number' || column.type === 'serial' ) ) {
		if ( filter.operator === 'between' ) {
			value = Array.isArray( value ) && value.length === 2 && value.every( ( x ) => whole( x ) !== '' )
				? value.map( whole )
				: '';
		} else {
			value = value === '' || value === undefined || value === null ? '' : whole( value );
		}
	}
	const empty =
		value === undefined || value === null || value === '' || ( Array.isArray( value ) && ! value.length );
	return empty ? null : { field: filter.field, operator: filter.operator, value };
}

/**
 * The filters that narrow and the trimmed search, as the REST takes them.
 *
 * @param {Object}      view    DataViews' view state
 * @param {Array}       columns the tab's columns
 * @param {string|null} search  the search text now typed, when DataViews' debounce has not yet passed it on
 */
export function narrowOf( view, columns, search = null ) {
	const byId = Object.fromEntries( columns.map( ( c ) => [ c.column, c ] ) );
	const filters = ( view.filters || [] )
		.map( ( f ) => filterOf( f, byId[ f.field ] ) )
		.filter( Boolean );
	return { filters, search: ( search === null ? view.search || '' : search ).trim() };
}

/** Whether a filter or a search narrows the view. */
export const isNarrowed = ( narrow ) => narrow.filters.length > 0 || narrow.search !== '';

/** The columns a filter narrows, for the header shade: a column's own, and those „Nincs válasz” names. */
export const filteredIds = ( narrow ) =>
	new Set( narrow.filters.flatMap( ( f ) => ( f.field === UNANSWERED ? f.value : [ f.field ] ) ) );
