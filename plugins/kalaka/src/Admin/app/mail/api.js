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
/** Sets the hourly sending budget; the server refuses anything but a whole number 1-2000 (`kalaka_mail_budget_invalid`). */
export const setBudget = ( value ) => runAbility( 'kalaka/set-mail-budget', { hourly_budget: value } );
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
 * Who a send to the selection would reach, and the bodies it may use: `{ queue, skipped, unknown, count, names, bodies }`,
 * with `count` the number of registrations the selection resolved to. A read that writes nothing; a POST because the
 * codes do not fit in a query string. The selection is `{ codes }` or the grid's view with its filters and search
 * (`grid/selection.js`).
 */
export const previewManual = ( path, selection ) =>
	apiFetch( {
		path,
		method: 'POST',
		data: { selection },
	} );

/**
 * Sends a body to the selection: `{ batch_id, queued, skipped, repeat, paused, held }`. The server resolves the
 * selection again at send time (the alternative, freezing the preview's codes, is not built) [UNAPPROVED N938]. The request key makes a repeat (a retry, a double click) return the first batch and
 * queue nothing.
 */
export const sendManual = ( event, bodyId, selection, layout, requestKey ) =>
	runAbility( 'kalaka/send-manual-mail', {
		event_id: event,
		body_id: bodyId,
		selection,
		layout,
		request_key: requestKey,
	} );
