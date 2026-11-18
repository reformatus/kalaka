/**
 * The sum row: a second `<tr>` in DataViews' own `<thead>`, filled through a portal. DataViews has no
 * summary row and no hook into its header, so the row is appended to the table's head and put back by a
 * MutationObserver when DataViews re-renders it. One cell per shown field, in DataViews' order.
 */
import { createPortal, useEffect, useLayoutEffect, useState } from '@wordpress/element';
import { Button, CheckboxControl, Dropdown } from '@wordpress/components';
import { fmt, sumParts } from './format';
import { SUM } from './texts';

function SumPicker( { column, chosen, onPick } ) {
	const [ selected, setSelected ] = useState( chosen );
	const key = chosen.join( '|' );
	useEffect( () => setSelected( chosen ), [ key ] );
	const label = fmt( SUM.pickLabel, column.label );
	return (
		<Dropdown
			className="kg-sumpick"
			popoverProps={ { placement: 'bottom-start' } }
			renderToggle={ ( { isOpen, onToggle } ) =>
				chosen.length ? (
					<Button
						variant="tertiary"
						size="compact"
						className="kg-sumpick-toggle"
						icon="arrow-down-alt2"
						aria-expanded={ isOpen }
						onClick={ onToggle }
						label={ label }
						showTooltip
					/>
				) : (
					<Button
						variant="tertiary"
						size="compact"
						className="kg-sumpick-toggle"
						aria-expanded={ isOpen }
						onClick={ onToggle }
						aria-label={ label + ' – ' + SUM.pick }
					>
						{ SUM.pick }
					</Button>
				)
			}
			renderContent={ () => (
				<fieldset className="kg-sumpick-box">
					<legend>{ label }</legend>
					{ column.members.map( ( member ) => (
						<CheckboxControl
							key={ member.value }
							label={ member.label }
							checked={ selected.includes( member.value ) }
							__nextHasNoMarginBottom
							onChange={ ( on ) => {
								const next = on
									? selected.concat( member.value )
									: selected.filter( ( x ) => x !== member.value );
								setSelected( next );
								onPick(
									column,
									column.members.map( ( m ) => m.value ).filter( ( v ) => next.includes( v ) )
								);
							} }
						/>
					) ) }
				</fieldset>
			) }
		/>
	);
}

export default function SumRow( { root, columns, order, sums, editable, onPick } ) {
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
			const counted = sums && sums[ column.column ] && sums[ column.column ].members;
			return (
				<td key={ column.column } className={ 'kg-sumtd' + ( column.numeric ? ' is-num' : '' ) }>
					{ parts && (
						<span className="kg-sumval" title={ full }>
							<span aria-hidden="true">{ parts.shown }</span>
							<span className="screen-reader-text">{ full }</span>
						</span>
					) }
					{ column.members && editable && (
						<SumPicker column={ column } chosen={ counted || [] } onPick={ onPick } />
					) }
				</td>
			);
		} );
	return createPortal( cells, row );
}
