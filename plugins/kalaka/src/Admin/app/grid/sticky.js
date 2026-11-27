/**
 * The start columns of the table that stay in view while it scrolls sideways: the selection checkbox (when the table
 * has one), then Kód and the name (the view's own identifying columns, when they lead the table), and at the other end
 * the actions column. They are made sticky in CSS (`grid.css`, by the host's `data-kg-sticky` count and
 * `data-kg-actions-loose`); this measures the columns and tells the host how many of them fit, and where each one
 * starts, as CSS variables `--kg-left-1` to `--kg-left-3`, because the columns' widths follow the rows on screen.
 *
 * The sticky columns together take at most a quarter of the room the table shows (the scroll container's visible
 * width), in the order checkbox, Kód, name, actions: a column that does not fit stays loose, and so does every
 * column after it.
 */
import { useLayoutEffect } from '@wordpress/element';

/** The most start columns there are: the checkbox, Kód and the name. */
export const MAX_STICKY = 3;

/** The share of the visible table width the sticky columns may take together. */
export const STICKY_SHARE = 0.25;

/**
 * How many leading columns stay: the checkbox column when the table has one, then the leading Kód and name columns of
 * the shown fields, in the table's order. Pure.
 *
 * @param {Object[]}      columns     the tab's columns
 * @param {string[]}      order       the shown fields, in the table's order
 * @param {boolean}       hasCheckbox whether the table has a selection column
 */
export function stickyCount( columns, order, hasCheckbox ) {
	const byId = Object.fromEntries( columns.map( ( c ) => [ c.column, c ] ) );
	let count = hasCheckbox ? 1 : 0;
	for ( const id of order || [] ) {
		const column = byId[ id ];
		if ( ! column || ! ( column.type === 'code' || column.name ) ) {
			break;
		}
		count++;
	}
	return Math.min( count, MAX_STICKY );
}

/**
 * How many of the start columns, and whether the actions column, may stay within the share of the visible width, by the
 * priority checkbox, Kód, name, actions: the first column that does not fit stops the run, so a later one never sticks
 * alone. The actions column sticks only when every start column does and it fits too. Pure.
 *
 * @param {number[]}    widths        the widths of the candidate start columns, in priority order
 * @param {number|null} actionsWidth  the actions column's width, null when the table has none
 * @param {number}      available     the visible width of the table's scroll container
 * @param {number}      [share]       the share the sticky columns may take together
 * @return {{count: number, actions: boolean}} the leading columns that stay, and whether the actions column does
 */
export function fitSticky( widths, actionsWidth, available, share = STICKY_SHARE ) {
	const budget = available * share;
	let used = 0;
	let count = 0;
	for ( const width of widths ) {
		if ( used + width > budget ) {
			break;
		}
		used += width;
		count++;
	}
	const actions = actionsWidth !== null && count === widths.length && used + actionsWidth <= budget;
	return { count, actions };
}

/** The left edge of each start column, from the widths of the columns before it. Pure. */
export const lefts = ( widths ) => widths.reduce( ( acc, width, index ) => [ ...acc, index === 0 ? 0 : acc[ index - 1 ] + widths[ index - 1 ] ], [] );

/**
 * Marks the host with the count that fits and keeps the offsets measured (on every change of the table and of its
 * size, and of the window, which resizes the scroll container).
 *
 * @param {Element|null} host       the grid's host element
 * @param {number}       candidates how many start columns could stay
 * @param {boolean}      hasActions whether the table has an actions column
 */
export default function useStickyColumns( host, candidates, hasActions = false ) {
	useLayoutEffect( () => {
		if ( ! host ) {
			return undefined;
		}
		// The table and its scroll container may not be there yet, and DataViews may replace them: each one found is
		// watched once, from the measuring itself.
		const watched = new Set();
		const watch = ( element ) => {
			if ( element && ! watched.has( element ) ) {
				watched.add( element );
				sizes.observe( element );
			}
		};
		const measure = () => {
			const scroller = host.querySelector( '.dataviews-layout__container' );
			watch( scroller );
			watch( host.querySelector( 'table' ) );
			const available = ( scroller || host ).clientWidth;
			if ( ! available ) {
				return; // not laid out (hidden): keep what it was
			}
			const heads = Array.from( host.querySelectorAll( 'table thead tr:first-child > th' ) );
			const widths = heads.slice( 0, Math.min( candidates, heads.length ) ).map( ( th ) => th.getBoundingClientRect().width );
			const actionsHead = hasActions ? host.querySelector( 'table thead tr:first-child > th.dataviews-view-table__actions-column' ) : null;
			const fit = fitSticky( widths, actionsHead ? actionsHead.getBoundingClientRect().width : null, available );
			lefts( widths.slice( 0, fit.count ) ).forEach( ( left, index ) => {
				const value = left.toFixed( 2 ) + 'px';
				if ( host.style.getPropertyValue( '--kg-left-' + ( index + 1 ) ) !== value ) {
					host.style.setProperty( '--kg-left-' + ( index + 1 ), value );
				}
			} );
			if ( fit.count > 0 ) {
				host.setAttribute( 'data-kg-sticky', String( fit.count ) );
			} else {
				host.removeAttribute( 'data-kg-sticky' );
			}
			// The actions column is DataViews' own sticky one; CSS lets it go loose while it does not fit.
			if ( hasActions && ! fit.actions ) {
				host.setAttribute( 'data-kg-actions-loose', '' );
			} else {
				host.removeAttribute( 'data-kg-actions-loose' );
			}
		};
		// DataViews re-renders a lot; measure once per frame rather than on every child change.
		let frame = 0;
		const schedule = () => {
			if ( ! frame ) {
				frame = window.requestAnimationFrame( () => {
					frame = 0;
					measure();
				} );
			}
		};
		const sizes = new window.ResizeObserver( schedule );
		const mutations = new window.MutationObserver( schedule );
		measure();
		mutations.observe( host, { childList: true, subtree: true } );
		return () => {
			if ( frame ) {
				window.cancelAnimationFrame( frame );
			}
			mutations.disconnect();
			sizes.disconnect();
			host.removeAttribute( 'data-kg-sticky' );
			host.removeAttribute( 'data-kg-actions-loose' );
		};
	}, [ host, candidates, hasActions ] );
}
