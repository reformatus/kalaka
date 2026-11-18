/** The grid's REST calls (`kalaka-admin/v1`, `GridRoutes`). */
import apiFetch from '@wordpress/api-fetch';

/**
 * One page of a view: `{ rows, total }`, from core's paging headers.
 *
 * @param {Object} config window.kalakaGrid
 * @param {number} view   the view (0: the master view)
 * @param {Object} query  { sort, desc, perPage, page }
 */
export async function fetchPage( config, view, { sort = null, desc = false, perPage = 50, page = 1 } ) {
	const params = new URLSearchParams( {
		per_page: String( Math.min( perPage, config.maxLimit ) ),
		page: String( page ),
		order: desc ? 'desc' : 'asc',
	} );
	if ( sort ) {
		params.set( 'orderby', sort );
	}
	const response = await apiFetch( {
		path: `${ config.rest }/views/${ view }/rows?${ params.toString() }`,
		parse: false,
	} );
	const rows = await response.json();
	return { rows, total: parseInt( response.headers.get( 'X-WP-Total' ) || '0', 10 ) };
}

/** Every row of a view, in pages of the most one page may hold, in the given order. */
export async function fetchAll( config, view, sort = null, desc = false ) {
	const rows = [];
	for ( let page = 1; ; page++ ) {
		const result = await fetchPage( config, view, { sort, desc, perPage: config.maxLimit, page } );
		rows.push( ...result.rows );
		if ( ! result.rows.length || rows.length >= result.total ) {
			return rows;
		}
	}
}

export const fetchSums = ( config, view ) => apiFetch( { path: `${ config.rest }/views/${ view }/sums` } );

export const saveCounts = ( config, view, column, members ) =>
	apiFetch( { path: `${ config.rest }/views/${ view }/counts`, method: 'PUT', data: { column, members } } );

export const fetchViews = ( config ) => apiFetch( { path: `${ config.rest }/views` } );

export const saveView = ( config, view, data ) =>
	apiFetch( {
		path: view ? `${ config.rest }/views/${ view }` : `${ config.rest }/views`,
		method: view ? 'PUT' : 'POST',
		data,
	} );

export const deleteView = ( config, view, version ) =>
	apiFetch( { path: `${ config.rest }/views/${ view }?version=${ version }`, method: 'DELETE' } );
