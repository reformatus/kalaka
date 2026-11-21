/**
 * The sum row: a second `<tr>` in DataViews' own `<thead>`, filled through a portal. DataViews has no
 * summary row and no hook into its header, so the row is appended to the table's head and put back by a
 * MutationObserver when DataViews re-renders it. One cell per shown field, in DataViews' order.
 *
 * Every cell carries one down arrow, which opens that column's menu (`ColumnMenu`: the sort commands and, for
 * a column that can count members, the counting choices; owner's walk, topic 6a G10 and round 3).
 */
import { createPortal, useLayoutEffect, useState } from '@wordpress/element';
import { sumParts } from './format';

export default function SumRow( { root, columns, order, sums, menuFor } ) {
	const [ row ] = useState( () => {
		const tr = document.createElement( 'tr' );
		tr.className = 'kg-sumtr';
		return tr;
	} );
	useLayoutEffect( () => {
		if ( ! root ) {
			return;
		}
		const ensure = () => {
			const thead = root.querySelector( 'table thead' );
			if ( thead && thead.lastElementChild !== row ) {
				thead.appendChild( row );
			}
		};
		ensure();
		const observer = new window.MutationObserver( ensure );
		observer.observe( root, { childList: true, subtree: true } );
		return () => {
			observer.disconnect();
			row.remove();
		};
	}, [ root, row ] );

	const byId = Object.fromEntries( columns.map( ( c ) => [ c.column, c ] ) );
	const cells = order
		.map( ( id ) => byId[ id ] )
		.filter( Boolean )
		.map( ( column ) => {
			const parts = sumParts( column, sums );
			const full = parts ? ( parts.named ? parts.full : column.label + ': ' + parts.full ) : '';
			return (
				<td key={ column.column } className={ 'kg-sumtd' + ( column.numeric ? ' is-num' : '' ) }>
					{ parts && (
						<span className="kg-sumval" title={ full }>
							<span aria-hidden="true">{ parts.shown }</span>
							<span className="screen-reader-text">{ full }</span>
						</span>
					) }
					{ menuFor( column ) }
				</td>
			);
		} );
	return createPortal( cells, row );
}
