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
 * @param {Function} options.onSent      called once a send is made
 */
import { ManualMailForm } from './ManualMailDialog';
import { GRIDACTION, MANUAL } from './texts';

export function manualMailAction( { eligible, event, previewPath, onSent } ) {
	return {
		id: 'kalaka-manual-mail',
		label: GRIDACTION.label,
		modalHeader: MANUAL.title,
		supportsBulk: true,
		isEligible: () => eligible,
		RenderModal: ( { items, closeModal } ) => (
			<ManualMailForm
				event={ event }
				previewPath={ previewPath }
				codes={ items.map( ( item ) => item.key ) }
				onClose={ closeModal }
				onSent={ onSent }
			/>
		),
	};
}
