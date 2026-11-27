/**
 * The sum row: a second `<tr>` in DataViews' own `<thead>`, filled through a portal. DataViews has no
 * summary row and no hook into its header, so the row is appended to the table's head and put back by a
 * MutationObserver when DataViews re-renders it. One cell per shown field, in DataViews' order.
 *
 * Every cell carries one down arrow, which opens that column's menu (`ColumnMenu`: the sort commands and, for
 * a column that can count members, the counting choices; owner's walk, topic 6a G10 and round 3).
 *
 * When the table carries row actions, DataViews puts a selection column before the fields and an actions column
 * after them; `framed` adds an empty cell at each end so every sum stays under its own column. The cell under the
 * actions column is sticky like the column's own cells, so a sum that scrolls under the header does not show
 * through below the sticky „Műveletek”. [UNAPPROVED N295]
 *
 * The cell under the select-all checkbox holds the count of the ticked rows (the number alone; screen readers get
 * „N kiválasztva”) and is empty while nothing is ticked. [UNAPPROVED N1034] [UNAPPROVED T969]
 */
import { createPortal, useLayoutEffect, useState } from '@wordpress/element';
import { fmt, sumParts } from './format';
import { GRID, SUM } from './texts';

export default function SumRow( { root, columns, order, sums, menuFor, narrowed, framed, selected = 0 } ) {
	const [ row ] = useState( () => {
		const tr = document.createElement( 'tr' );
		tr.className = 'kg-sumtr';
		return tr;
	} );
	// Whether DataViews draws the divider on its own sticky cells (its header cell carries `--stuck`); the sum's
	// frame cell follows it. [UNAPPROVED N295]
	const [ stuck, setStuck ] = useState( false );
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
			setStuck( !! root.querySelector( 'table thead th.dataviews-view-table__actions-column--stuck' ) );
		};
		ensure();
		const observer = new window.MutationObserver( ensure );
		observer.observe( root, { childList: true, subtree: true, attributes: true, attributeFilter: [ 'class' ] } );
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
			return (
				<td key={ column.column } className={ 'kg-sumtd' + ( column.numeric ? ' is-num' : '' ) } title={ note }>
					{ parts && (
						<span className="kg-sumval" title={ note ? full + ' ' + note : full }>
							<span aria-hidden="true">{ parts.shown }</span>
							<span className="screen-reader-text">{ full }</span>
						</span>
					) }
					{ menuFor( column ) }
				</td>
			);
		} );
	return createPortal(
		<>
			{ framed && (
				<td className="kg-sumtd kg-sumtd-frame dataviews-view-table__checkbox-column">
					{ selected > 0 && (
						<span className="kg-sumsel">
							<span aria-hidden="true">{ selected }</span>
							<span className="screen-reader-text">{ fmt( SUM.selected, selected ) }</span>
						</span>
					) }
				</td>
			) }
			{ cells }
			{ framed && <td className={ 'kg-sumtd kg-sumtd-frame kg-sumtd-actions dataviews-view-table__actions-column--sticky' + ( stuck ? ' dataviews-view-table__actions-column--stuck' : '' ) } /> }
		</>,
		row
	);
}
