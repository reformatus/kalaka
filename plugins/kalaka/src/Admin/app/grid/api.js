/**
 * The grid's REST calls. Reads go to Kaláka's own routes (`kalaka-admin/v1`, `GridRoutes`); writes run the
 * abilities through core's run endpoint (`POST /wp-abilities/v1/abilities/{name}/run`, `{ input }`).
 */
import apiFetch from '@wordpress/api-fetch';

/** The query parameters of the grid's filters and search; none when nothing narrows. */
function narrowing( params, narrow ) {
	if ( narrow && narrow.filters.length ) {
		params.set( 'filters', JSON.stringify( narrow.filters ) );
	}
	if ( narrow && narrow.search ) {
		params.set( 'search', narrow.search );
	}
	return params;
}

/**
 * One page of a view: `{ rows, total }`, from core's paging headers.
 *
 * @param {Object} config window.kalakaGrid
 * @param {Object} tab    the tab: { view, grant } (view 0: the master view; grant: the grant a grant holder's tab is)
 * @param {Object} query  { sort, desc, perPage, page, narrow } with `narrow` as `{ filters, search }`
 */
export async function fetchPage(
	config,
	tab,
	{ sort = null, desc = false, perPage = 50, page = 1, narrow = null }
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
	narrowing( params, narrow );
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

/** Every row the filters and search leave, in pages of the most one page may hold, in the given order. */
export async function fetchAll( config, tab, sort = null, desc = false, narrow = null ) {
	const rows = [];
	for ( let page = 1; ; page++ ) {
		const result = await fetchPage( config, tab, {
			sort,
			desc,
			perPage: config.maxLimit,
			page,
			narrow,
		} );
		rows.push( ...result.rows );
		if ( ! result.rows.length || rows.length >= result.total ) {
			return rows;
		}
	}
}

/**
 * The sum row over the whole view, or over the rows the filters and search leave, with an optional ad hoc
 * choice of counted members (a grant holder's own counting, never saved to the shared view; topic 6a G1).
 *
 * @param {Object}      config window.kalakaGrid
 * @param {Object}      tab    the tab
 * @param {Object|null} narrow { filters, search }
 * @param {Object|null} counts column → member list; null: the stored choice
 */
export const fetchSums = ( config, tab, narrow = null, counts = null ) => {
	const params = new URLSearchParams();
	if ( tab.grant ) {
		params.set( 'grant', String( tab.grant ) );
	}
	narrowing( params, narrow );
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
 * returns a value (`kalaka/save-master-counts`: `{ id }`). Rejects with the error as `apiFetch` gives it
 * (`{ code, message, data }`).
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
 * just before. No members removes the column's entry. The sum row it answers is over the narrowed rows.
 */
export async function saveCounts( config, tab, column, members, narrow = null ) {
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
	return fetchSums( config, tab, narrow );
}

/**
 * One registration's detail (S38): `{ registration, fields, money?, can }`, each part only as the user's views
 * and grants allow. The code is the identity; a row the user's views do not hold is a 404.
 *
 * @param {Object} config window.kalakaGrid
 * @param {string} code   the registration's code
 */
export const fetchDetail = ( config, code ) =>
	apiFetch( { path: `${ config.rest }/registrations/${ encodeURIComponent( code ) }` } );

/**
 * What a failed read means: an unknown code is a 404 ('missing'), a known code outside the holder's views a 403
 * `kalaka_registration_not_in_view` ('noAccess'), anything else 'failed'. [UNAPPROVED T16]
 */
export function detailProblem( error ) {
	if ( error && error.code === 'kalaka_registration_not_in_view' ) {
		return 'noAccess';
	}
	return error && error.data && error.data.status === 404 ? 'missing' : 'failed';
}

/**
 * Records a manual payment (`kalaka/record-payment`), a refund (`kalaka/record-refund`) or a correction
 * (`kalaka/record-correction`) through the abilities, and answers `{ id }`. A payment and a refund carry a
 * method and a value date; a correction carries neither, but a direction (`in` or `out`) and its reason. The
 * idempotency key makes a retry of the same entry safe.
 *
 * @param {Object} config window.kalakaGrid
 * @param {Object} entry  { kind, code, currency, payer, amount, valueDate, method, note, direction, key }, `kind`
 *                        being `payment`, `refund` or `correction`
 */
export const recordEntry = (
	config,
	{ kind, code, currency, payer, amount, valueDate, method, note, direction, key }
) => {
	const input = {
		event_id: config.event,
		code,
		payer,
		amount,
		currency,
		idempotency_key: key,
	};
	if ( kind === 'correction' ) {
		input.direction = direction;
		input.reason = note;
		return runAbility( 'kalaka/record-correction', input );
	}
	input.method = method;
	input.value_date = valueDate;
	if ( note ) {
		input.note = note;
	}
	return runAbility( kind === 'refund' ? 'kalaka/record-refund' : 'kalaka/record-payment', input );
};

/**
 * Confirms a registration without payment (`kalaka/set-manual-confirmation`) or unconfirms it
 * (`kalaka/unconfirm`, which also clears the manual flag). The reason is required (at most 500 characters);
 * both are idempotent, so a retry is safe: unconfirming a registration that is not confirmed answers
 * `{ changed: false }`. The unconfirm is refused with a 422 when its payments reach the threshold or it was
 * confirmed because nothing was due.
 *
 * @param {Object}  config    window.kalakaGrid
 * @param {string}  code      the registration's code
 * @param {string}  reason    why
 * @param {boolean} unconfirm unconfirm (else set the manual flag)
 */
export const setManualConfirmation = ( config, code, reason, unconfirm ) =>
	runAbility( unconfirm ? 'kalaka/unconfirm' : 'kalaka/set-manual-confirmation', {
		event_id: config.event,
		code,
		reason,
	} );
