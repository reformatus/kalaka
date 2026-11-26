/**
 * What a bulk action acts on, as the server takes it: one `selection` field. The ticked rows are
 * `{ codes }`; with nothing ticked it is every row the tab's view holds that matches the current filters and search,
 * across all pages, as `{ view, grant, filters?, search? }` (the same `filters` and `search` the grid's own read
 * sends). Pure: no WordPress imports, so Jest runs it as it is. [UNAPPROVED N937]
 *
 * @param {Object[]} rows   the ticked rows (`{ key }`)
 * @param {Object}   tab    the tab: { view, grant }
 * @param {Object}   narrow `{ filters, search }` as `narrowOf()` gives it
 */
export function selectionBody( rows, tab, narrow ) {
	if ( rows.length ) {
		return { codes: rows.map( ( row ) => row.key ) };
	}
	const body = { view: tab.view, grant: tab.grant || 0 };
	if ( narrow && narrow.filters.length ) {
		body.filters = narrow.filters;
	}
	if ( narrow && narrow.search ) {
		body.search = narrow.search;
	}
	return body;
}

/** Whether a selection is the „all matching” form (no codes). */
export const isAllMatching = ( selection ) => ! Array.isArray( selection.codes );

/** Whether the „all matching” form is narrowed by a filter or a search. */
export const isNarrowedSelection = ( selection ) =>
	isAllMatching( selection ) && !! ( ( selection.filters && selection.filters.length ) || selection.search );

/**
 * A selection as one string: the same codes in any order, or the same view, grant, filters and search, are the same
 * selection (the repeat guard's key and the preview's refetch both depend on it).
 */
export function selectionSignature( selection ) {
	if ( Array.isArray( selection ) ) {
		return [ ...selection ].sort().join( ',' );
	}
	if ( ! isAllMatching( selection ) ) {
		return [ ...selection.codes ].sort().join( ',' );
	}
	return JSON.stringify( [ selection.view, selection.grant || 0, selection.filters || [], selection.search || '' ] );
}
