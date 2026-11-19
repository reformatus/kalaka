/**
 * The grid's REST calls. Reads go to Kaláka's own routes (`kalaka-admin/v1`, `GridRoutes`); a write would run an
 * ability through core's run endpoint (`POST /wp-abilities/v1/abilities/{name}/run`, `{ input }`).
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
 * choice of counted members (the viewer's own counting for the session, never saved to the view; topic 6a G1, B1).
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

/**
 * Runs an ability through core's run endpoint and answers what it returned; every ability the grid runs
 * returns a value. The grid itself runs none: no view configuration is saved from it (topic 6a B1). Rejects
 * with the error as `apiFetch` gives it (`{ code, message, data }`).
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
