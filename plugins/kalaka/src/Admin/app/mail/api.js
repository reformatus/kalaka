/**
 * The mail screens' REST calls. Reads go to Kaláka's own routes (`kalaka-admin/v1`, `Mail\Rest\MailRoutes`); acts
 * run an ability through core's run endpoint (`runAbility`, grid/api.js). The screen decides no access: what it
 * shows and offers comes from the reads (`can`, `refusals`), and the server decides every call.
 */
import apiFetch from '@wordpress/api-fetch';
import { runAbility } from '../grid/api';

export { runAbility };

/** A read: `path` is after `kalaka-admin/v1`. */
export const read = ( rest, path ) => apiFetch( { path: `${ rest }${ path }` } );

/** A read by its full path (after the site's REST root already in it). */
export const readPath = ( path ) => apiFetch( { path } );

/**
 * Switches a mail on or off, assigns its body (null: none) or sets whether it is wrapped in the layout; at least
 * one of the three. Answers the event's settings.
 */
export const updateMail = ( event, mail, change ) =>
	runAbility( 'kalaka/update-action-mail', { event_id: event, mail, ...change } );

/** Saves the event's layout HTML. */
export const saveLayout = ( event, html ) => runAbility( 'kalaka/set-event-mail-layout', { event_id: event, html } );

export const pause = () => runAbility( 'kalaka/pause-mail', {} );
export const resume = () => runAbility( 'kalaka/resume-mail', {} );
export const clearQueue = () => runAbility( 'kalaka/clear-mail-queue', {} );
export const clearEvent = ( event ) => runAbility( 'kalaka/clear-event-mail', { event_id: event } );

/** A new, empty draft body of the event through core's own route; answers the post. */
export const createBody = ( event, title ) =>
	apiFetch( {
		path: '/wp/v2/kalaka_mail',
		method: 'POST',
		data: { title, status: 'draft', meta: { kalaka_event_id: event } },
	} );

/**
 * Who a send of the codes would reach, and the bodies it may use: `{ queue, skipped, unknown, bodies }`. A read
 * that writes nothing; a POST because 1000 codes do not fit in a query string.
 */
export const previewManual = ( path, codes ) =>
	apiFetch( {
		path,
		method: 'POST',
		data: { codes },
	} );

/**
 * Sends a body to the codes: `{ batch_id, queued, skipped, repeat, paused, held }`. The request key makes a repeat
 * (a retry, a double click) return the first batch and queue nothing.
 */
export const sendManual = ( event, bodyId, codes, layout, requestKey ) =>
	runAbility( 'kalaka/send-manual-mail', {
		event_id: event,
		body_id: bodyId,
		codes,
		layout,
		request_key: requestKey,
	} );
