/**
 * The grid's bulk action „Levél küldése” (piece 4): a DataViews action over the ticked rows, offered on a tab only
 * when the page's configuration says the tab carries the manual mail (full access, or a grant with
 * `kalaka_manual_mail`; the ability and the preview decide again on every call). The codes are the ticked rows'
 * keys; the dialog is the manual mail form in DataViews' own action modal. DataViews adds a menu with this one
 * action to every row as well. [UNAPPROVED N287] [UNAPPROVED N289] [UNAPPROVED N295]
 *
 * @param {Object}   options
 * @param {boolean}  options.eligible    whether the tab carries the manual mail
 * @param {number}   options.event       the event
 * @param {string}   options.previewPath the preview route after the site's REST root
 * @param {Function} options.onSent      called when the dialog closes after a send was made (not before: clearing the
 *                                       selection earlier would unmount the action's modal and the answer in it)
 */
import { useEffect, useRef } from '@wordpress/element';
import { envelope } from '@wordpress/icons';
import { ManualMailForm } from './ManualMailDialog';
import { pageKeys } from './manual';
import { GRIDACTION, MANUAL } from './texts';

/**
 * DataViews closes its modal by itself on × and Esc, without asking the dialog, so the ticks are cleared when the
 * dialog goes away after a send, however it was closed: not at the send (that would unmount the answer) and not in
 * the Bezárás button only. [UNAPPROVED N288]
 */
function BulkDialog( { event, previewPath, items, closeModal, onSent, keys } ) {
	const sent = useRef( false );
	const done = useRef( onSent );
	done.current = onSent;
	useEffect(
		() => () => {
			if ( sent.current ) {
				sent.current = false;
				done.current();
			}
		},
		[]
	);
	return (
		<ManualMailForm
			event={ event }
			previewPath={ previewPath }
			codes={ items.map( ( item ) => item.key ) }
			onClose={ closeModal }
			onSent={ () => {
				sent.current = true;
			} }
			keys={ keys }
		/>
	);
}

export function manualMailAction( { eligible, event, previewPath, onSent } ) {
	// The event's request keys for the page load (not for this action object, which the grid rebuilds when the tab
	// changes): the dialog is opened and closed on the same rows and body as often as the admin likes and that stays
	// one request. [UNAPPROVED N292]
	const keys = pageKeys( event );
	return {
		id: 'kalaka-manual-mail',
		label: GRIDACTION.label,
		modalHeader: MANUAL.title,
		// DataViews shows an action on a narrow screen only when it has an icon (then as the icon alone). [UNAPPROVED N287]
		icon: envelope,
		supportsBulk: true,
		isEligible: () => eligible,
		RenderModal: ( { items, closeModal } ) => (
			<BulkDialog event={ event } previewPath={ previewPath } items={ items } closeModal={ closeModal } onSent={ onSent } keys={ keys } />
		),
	};
}
