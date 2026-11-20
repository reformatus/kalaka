/**
 * The sum row: a second `<tr>` in DataViews' own `<thead>`, filled through a portal. DataViews has no
 * summary row and no hook into its header, so the row is appended to the table's head and put back by a
 * MutationObserver when DataViews re-renders it. One cell per shown field, in DataViews' order.
 *
 * A cell whose column can count members carries one down arrow; it opens that column's own header menu,
 * where the counting choices live (owner's walk, topic 6a G10). A column that cannot count members shows
 * no arrow.
 */
import { createPortal, useLayoutEffect, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { fmt, sumParts } from './format';
import { SUM } from './texts';

export default function SumRow( { root, columns, order, sums, onOpenMenu } ) {
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
			// Only a column that can count members has the counting choices, and only one with a header menu
			// can open them; anything else (a total, an average, a yes count, the row count) shows no arrow.
			const countable = !! ( column.members && column.members.length ) && column.sortable !== false;
			return (
				<td key={ column.column } className={ 'kg-sumtd' + ( column.numeric ? ' is-num' : '' ) }>
					{ parts && (
						<span className="kg-sumval" title={ full }>
							<span aria-hidden="true">{ parts.shown }</span>
							<span className="screen-reader-text">{ full }</span>
						</span>
					) }
					{ countable && (
						<Button
							variant="tertiary"
							size="compact"
							className="kg-sumopen"
							icon="arrow-down-alt2"
							label={ fmt( SUM.open, column.label ) }
							showTooltip
							onClick={ () => onOpenMenu( column ) }
						/>
					) }
				</td>
			);
		} );
	return createPortal( cells, row );
}
