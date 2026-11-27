/**
 * The grid's REST calls. Reads go to Kaláka's own routes (`kalaka-admin/v1`, `GridRoutes`); a write would run an
 * ability through core's run endpoint (`POST /wp-abilities/v1/abilities/{name}/run`, `{ input }`).
 */
import apiFetch from '@wordpress/api-fetch';
import { buildCall } from './money';

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
 * `kalaka_registration_not_in_view` ('noAccess'), anything else 'failed'.
 */
export function detailProblem( error ) {
	if ( error && error.code === 'kalaka_registration_not_in_view' ) {
		return 'noAccess';
	}
	return error && error.data && error.data.status === 404 ? 'missing' : 'failed';
}

/**
 * Records one entry through the abilities, and answers `{ id }`: a payment (`kalaka/record-payment`), a refund
 * (`kalaka/record-refund`), a correction (`kalaka/record-correction`), or a charge or discount
 * (`kalaka/record-charge`, `kalaka/record-discount`; SEAM, Backend #209's design note §9). Which ability
 * and which input is `buildCall`'s (money.js), which also refuses a type the read's `can` does not allow, so a
 * charge or discount is never called unless it is offered. The idempotency key makes a retry of the same entry
 * safe.
 *
 * @param {Object} config window.kalakaGrid
 * @param {Object} entry  see `buildCall`
 * @param {Object} can    the read's `can`
 */
export const recordEntry = ( config, entry, can ) => {
	const { ability, input } = buildCall( config.event, entry, can );
	return runAbility( ability, input );
};

/**
 * Sets the manual confirmation flag (`kalaka/set-manual-confirmation`) or runs the one unconfirm
 * (`kalaka/unconfirm`: it revokes the flag if it is set and records unconfirmed with the reason; the rules then
 * decide again). The reason is required (at most 500 characters). Both answer `{ changed }`: `false` means
 * nothing was written (the registration was not confirmed, or the flag was already set). Only the set is
 * idempotent. A retry of the unconfirm is a new act: on a registration the rules confirmed again at once it
 * unconfirms and re-confirms a second time, with its own records and audit entry, and there is no idempotency
 * key. So the dialog sends it once and never retries it by itself. The unconfirm has no refusal.
 *
 * @param {Object}  config    window.kalakaGrid
 * @param {string}  code      the registration's code
 * @param {string}  reason    why
 * @param {boolean} unconfirm the unconfirm (else set the manual flag)
 */
export const setManualConfirmation = ( config, code, reason, unconfirm ) =>
	runAbility( unconfirm ? 'kalaka/unconfirm' : 'kalaka/set-manual-confirmation', {
		event_id: config.event,
		code,
		reason,
	} );
