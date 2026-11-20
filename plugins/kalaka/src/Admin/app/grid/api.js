/**
 * The grid's REST calls. Reads go to Kaláka's own routes (`kalaka-admin/v1`, `GridRoutes`); writes run the
 * abilities through core's run endpoint (`POST /wp-abilities/v1/abilities/{name}/run`, `{ input }`).
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * One page of a view: `{ rows, total }`, from core's paging headers.
 *
 * @param {Object} config window.kalakaGrid
 * @param {Object} tab    the tab: { view, grant } (view 0: the master view; grant: the grant a grant holder's tab is)
 * @param {Object} query  { sort, desc, perPage, page }
 */
export async function fetchPage(
	config,
	tab,
	{ sort = null, desc = false, perPage = 50, page = 1 }
) {
	const params = new URLSearchParams( {
		per_page: String( Math.min( perPage, config.maxLimit ) ),
		page: String( page ),
		order: desc ? 'desc' : 'asc',
	} );
	if ( sort ) {
		params.set( 'orderby', sort );
	}
	if ( tab.grant ) {
		params.set( 'grant', String( tab.grant ) );
	}
	const response = await apiFetch( {
		path: `${ config.rest }/views/${ tab.view }/rows?${ params.toString() }`,
		parse: false,
	} );
	const rows = await response.json();
	return {
		rows,
		total: parseInt( response.headers.get( 'X-WP-Total' ) || '0', 10 ),
	};
}

/** Every row of a view, in pages of the most one page may hold, in the given order. */
export async function fetchAll( config, tab, sort = null, desc = false ) {
	const rows = [];
	for ( let page = 1; ; page++ ) {
		const result = await fetchPage( config, tab, {
			sort,
			desc,
			perPage: config.maxLimit,
			page,
		} );
		rows.push( ...result.rows );
		if ( ! result.rows.length || rows.length >= result.total ) {
			return rows;
		}
	}
}

/**
 * The sum row over the whole view, with an optional ad hoc choice of counted members (a grant holder's own
 * counting, never saved to the shared view; topic 6a G1).
 *
 * @param {Object}      config  window.kalakaGrid
 * @param {Object}      tab     the tab
 * @param {Object|null} counts  column → member list; null: the stored choice
 */
export const fetchSums = ( config, tab, counts = null ) => {
	const params = new URLSearchParams();
	if ( tab.grant ) {
		params.set( 'grant', String( tab.grant ) );
	}
	if ( counts ) {
		params.set( 'counts', JSON.stringify( counts ) );
	}
	const query = params.toString();
	return apiFetch( {
		path: `${ config.rest }/views/${ tab.view }/sums${ query ? '?' + query : '' }`,
	} );
};

export const fetchViews = ( config ) =>
	apiFetch( { path: `${ config.rest }/views` } );

/**
 * Runs an ability through core's run endpoint and answers what it returned; every ability the grid runs
 * returns a value (`kalaka/delete-view` and `kalaka/save-master-counts`: `{ id }`). Rejects with the error
 * as `apiFetch` gives it (`{ code, message, data }`).
 *
 * @param {string} name  the ability
 * @param {Object} input its input
 */
export const runAbility = ( name, input ) =>
	apiFetch( {
		path: `/wp-abilities/v1/abilities/${ name }/run`,
		method: 'POST',
		data: { input },
	} );

/**
 * Saves a stored view through `kalaka/save-view`; its `version` is sent back.
 * Only the keys the ability's schema names are sent.
 *
 * @param {Object} config window.kalakaGrid
 * @param {number} view   the view's ID
 * @param {Object} data   { name, filter, columns, counts?, version? }
 */
export const saveView = (
	config,
	view,
	{ name, filter, columns, counts, version }
) => {
	const input = { event_id: config.event, id: view, name, filter, columns, version };
	if ( counts !== undefined && counts !== null ) {
		input.counts = counts;
	}
	return runAbility( 'kalaka/save-view', input );
};

/**
 * Saves the members one column's sum counts and answers the new sum row: for the master view (0) through
 * `kalaka/save-master-counts`; for a view through `kalaka/save-view`, with its stored shape and version read
 * just before. No members removes the column's entry.
 */
export async function saveCounts( config, tab, column, members ) {
	const view = tab.view;
	if ( ! view ) {
		await runAbility( 'kalaka/save-master-counts', {
			event_id: config.event,
			column,
			members,
		} );
	} else {
		const list = await fetchViews( config );
		const stored = ( list.views || [] ).find( ( x ) => x.view === view );
		if ( ! stored ) {
			throw new Error( '' );
		}
		const counts = { ...stored.shape.counts };
		if ( members.length ) {
			counts[ column ] = members;
		} else {
			delete counts[ column ];
		}
		await saveView( config, view, {
			version: stored.version,
			name: stored.name,
			filter: stored.shape.filter,
			columns: stored.shape.columns,
			counts,
		} );
	}
	return fetchSums( config, tab );
}
