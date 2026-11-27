/**
 * The sum row: a second `<tr>` in DataViews' own `<thead>`, filled through a portal. DataViews has no
 * summary row and no hook into its header, so the row is appended to the table's head and put back by a
 * MutationObserver when DataViews re-renders it. One cell per shown field, in DataViews' order.
 *
 * A column that can count offers its counting menu from the number itself (owner, 2026-11-27): the number is
 * the trigger, there is no down arrow, and a column that can count but has nothing counted yet shows a grey
 * placeholder with a dotted underline. Columns that cannot count keep a plain value.
 */
import { createPortal, useLayoutEffect, useState } from '@wordpress/element';
import { sumParts } from './format';
import { GRID } from './texts';

export default function SumRow( { root, columns, order, sums, menuFor, narrowed } ) {
	const [ row ] = useState( () => {
		const tr = document.createElement( 'tr' );
		tr.className = 'kg-sumtr';
		return tr;
	} );
	// While filtered or searched the sums follow the filter: italics (CSS) and a tooltip say so.
	useLayoutEffect( () => {
		row.classList.toggle( 'is-narrowed', !! narrowed );
	}, [ row, narrowed ] );
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
			const note = narrowed ? GRID.followsFilter : undefined;
			const counts = !! ( column.members && column.members.length );
			const menu = counts ? menuFor( column, parts ? parts.shown : null ) : null;
			return (
				<td
					key={ column.column }
					className={ 'kg-sumtd' + ( column.numeric ? ' is-num' : '' ) }
					title={ note }
				>
					{ parts && <span className="screen-reader-text">{ full }</span> }
					{ menu ||
						( parts && (
							<span className="kg-sumval" aria-hidden="true" title={ note ? full + ' ' + note : full }>
								{ parts.shown }
							</span>
						) ) }
				</td>
			);
		} );
	return createPortal( cells, row );
}
