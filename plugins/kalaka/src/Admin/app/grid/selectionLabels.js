/**
 * Names the row checkboxes. DataViews labels each one with its title field and says „(no title)” when the view has
 * none; a title field would add a column of its own to the table, so the label is set here instead: the checkbox of
 * the n-th body row gets the n-th row's key (the registration's code). No WordPress imports.
 */

/** The checkboxes of the table's body rows, one per row in order, `null` for a row that has none. */
export function rowCheckboxes( root ) {
	return Array.from( root.querySelectorAll( 'table tbody tr' ) ).map( ( tr ) =>
		tr.querySelector( 'td.dataviews-view-table__checkbox-column input[type="checkbox"]' )
	);
}

/**
 * @param {Element}  root  the grid's host element
 * @param {string[]} keys  the shown rows' keys, in the order of the rows
 * @param {Function} label `( key ) => the accessible name`
 */
export function labelRowCheckboxes( root, keys, label ) {
	const boxes = rowCheckboxes( root );
	boxes.forEach( ( box, index ) => {
		if ( box && keys[ index ] !== undefined ) {
			const text = label( keys[ index ] );
			if ( box.getAttribute( 'aria-label' ) !== text ) {
				box.setAttribute( 'aria-label', text );
			}
		}
	} );
}
