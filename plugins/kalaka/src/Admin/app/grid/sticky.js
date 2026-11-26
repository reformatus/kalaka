/**
 * The start columns of the table that stay in view while it scrolls sideways: the selection checkbox (when the table
 * has one), then Kód and the name (the view's own identifying columns, when they lead the table). The cells are
 * made sticky in CSS (`grid.css`, by the host's `data-kg-sticky` count); this only tells the host how many columns
 * that is and measures where each one starts, as CSS variables `--kg-left-1` to `--kg-left-3`, because the columns'
 * widths follow the rows on screen. [UNAPPROVED N942]
 */
import { useLayoutEffect } from '@wordpress/element';

/** The most start columns there are: the checkbox, Kód and the name. */
export const MAX_STICKY = 3;

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

/** The left edge of each start column, from the widths of the columns before it. Pure. */
export const lefts = ( widths ) => widths.reduce( ( acc, width, index ) => [ ...acc, index === 0 ? 0 : acc[ index - 1 ] + widths[ index - 1 ] ], [] );

/**
 * Marks the host with the count and keeps the offsets measured (on every change of the table and of its size).
 *
 * @param {Element|null} host  the grid's host element
 * @param {number}       count how many start columns stay
 */
export default function useStickyColumns( host, count ) {
	useLayoutEffect( () => {
		if ( ! host ) {
			return undefined;
		}
		if ( count === 0 ) {
			host.removeAttribute( 'data-kg-sticky' );
			return undefined;
		}
		const measure = () => {
			const heads = host.querySelectorAll( 'table thead tr:first-child > th' );
			const widths = Array.from( heads )
				.slice( 0, count )
				.map( ( th ) => th.getBoundingClientRect().width );
			lefts( widths ).forEach( ( left, index ) => {
				const value = left.toFixed( 2 ) + 'px';
				if ( host.style.getPropertyValue( '--kg-left-' + ( index + 1 ) ) !== value ) {
					host.style.setProperty( '--kg-left-' + ( index + 1 ), value );
				}
			} );
		};
		host.setAttribute( 'data-kg-sticky', String( count ) );
		measure();
		const mutations = new window.MutationObserver( measure );
		mutations.observe( host, { childList: true, subtree: true } );
		const sizes = new window.ResizeObserver( measure );
		const table = host.querySelector( 'table' );
		if ( table ) {
			sizes.observe( table );
		}
		return () => {
			mutations.disconnect();
			sizes.disconnect();
			host.removeAttribute( 'data-kg-sticky' );
		};
	}, [ host, count ] );
}
